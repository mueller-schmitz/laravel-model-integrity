<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Files;

use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Events\IntegrityViolationDetected;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Recording\ChainName;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityErrorType;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Stores files content-addressed under their SHA-256 hash. Identical content
 * is stored once; a file with the right content is never replaced.
 *
 * The file is written before the database transaction, so the global chain
 * head is not locked during the upload. Writes go to a temporary name and are
 * moved into place, so an aborted write never leaves a partial file under the
 * final name.
 */
class FileStore
{
    /**
     * @param  SplFileInfo|string|resource  $file  an uploaded file, a file object, a local path or a readable stream
     */
    public function store(mixed $file, ?string $mime = null): StoredFile
    {
        [$path, $temporary] = $this->localPath($file);

        try {
            $sha256 = hash_file('sha256', $path);
            $size = filesize($path);

            if ($sha256 === false || $size === false) {
                throw new RuntimeException("Cannot read file [{$path}].");
            }

            $diskName = Config::string('model-integrity.files.disk', 'local');
            $target = $this->targetPath($sha256);

            // A committed record means its file was in place: if it is missing
            // now, it was lost or deleted outside the application.
            $recorded = $this->find($sha256) !== null;
            $wasMissing = $this->ensureOnDisk(Storage::disk($diskName), $diskName, $target, $path, $sha256);

            $file = $this->record($sha256, $diskName, $target, $size, $mime ?? $this->detectMime($path));

            if ($recorded && $wasMissing) {
                $this->reportFileProblem("File [{$target}] on disk [{$diskName}] was missing and has been restored.");
            }

            return $file;
        } finally {
            if ($temporary) {
                @unlink($path);
            }
        }
    }

    public function find(string $sha256): ?StoredFile
    {
        return StoredFile::query()->where('sha256', $sha256)->first();
    }

    /**
     * Makes sure the target holds exactly this content. A missing file is
     * written (also for an existing record: it heals a lost file); a file with
     * other content is kept as evidence under another name and replaced.
     *
     * @return bool whether the target was missing
     */
    private function ensureOnDisk(Filesystem $disk, string $diskName, string $target, string $path, string $sha256): bool
    {
        $wasMissing = ! $disk->exists($target);

        if (! $wasMissing) {
            if ($this->hashOnDisk($disk, $diskName, $target) === $sha256) {
                return false;
            }

            $evidence = $target.'.corrupt-'.Carbon::now('UTC')->format('YmdHis').'-'.Str::random(6);

            // Never replace the file unless it is kept as evidence.
            if (! $disk->move($target, $evidence)) {
                throw new RuntimeException("File [{$target}] on disk [{$diskName}] does not match its hash, and it could not be moved away as evidence.");
            }

            $this->reportFileProblem("File [{$target}] on disk [{$diskName}] did not match its hash [{$sha256}]; it was kept as [{$evidence}] and replaced.");
        }

        $temporary = $target.'.tmp-'.Str::random(16);
        $stream = fopen($path, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Cannot read file [{$path}].");
        }

        try {
            if (! $disk->writeStream($temporary, $stream)) {
                throw new RuntimeException("Cannot write file [{$temporary}] to disk [{$diskName}].");
            }
        } catch (Throwable $e) {
            rescue(fn () => $disk->delete($temporary), report: false);

            throw $e;
        } finally {
            fclose($stream);
        }

        // A parallel store of the same content may have moved its copy into
        // place meanwhile; the content is identical, so replacing it is harmless.
        if (! $disk->move($temporary, $target)) {
            $disk->delete($temporary);

            throw new RuntimeException("Cannot move file [{$temporary}] to [{$target}] on disk [{$diskName}].");
        }

        return $wasMissing;
    }

    private function record(string $sha256, string $disk, string $path, int $size, ?string $mime): StoredFile
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $sha256, $disk, $path, $size, $mime): StoredFile {
            // The global head lock serializes stores of the same content without
            // locking integrity_files, on which the application may only INSERT.
            $head = $connection->table(Config::string('model-integrity.tables.heads'))
                ->where('chain', ChainName::GLOBAL)
                ->lockForUpdate()
                ->first();

            if ($head === null) {
                throw IntegrityConfigurationException::headMissing(ChainName::GLOBAL);
            }

            if (($existing = $this->find($sha256)) !== null) {
                return $existing;
            }

            try {
                return StoredFile::query()->create([
                    'sha256' => $sha256,
                    'disk' => $disk,
                    'path' => $path,
                    'size' => $size,
                    'mime' => $mime,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // The caller's transaction read earlier, so under REPEATABLE READ
                // its snapshot misses a row a parallel store has committed.
                return $this->findCommitted($connection, $sha256) ?? throw $e;
            }
        });
    }

    /**
     * Reads the latest committed row regardless of the transaction's snapshot:
     * a shared lock on MySQL/MariaDB (needs SELECT only), a plain read on
     * PostgreSQL, whose default READ COMMITTED sees committed rows.
     */
    private function findCommitted(Connection $connection, string $sha256): ?StoredFile
    {
        $query = StoredFile::query()->where('sha256', $sha256);

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $query->sharedLock();
        }

        return $query->first();
    }

    /**
     * A file that cannot be read is not reported as corrupt: that would be a
     * false alarm for a permission problem or a transient disk error.
     */
    private function hashOnDisk(Filesystem $disk, string $diskName, string $path): string
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Cannot read the existing file [{$path}] on disk [{$diskName}] to compare its content.");
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    private function reportFileProblem(string $message): void
    {
        event(new IntegrityViolationDetected(new IntegrityResult([new IntegrityError(
            IntegrityErrorType::FileMismatch,
            $message,
            (new StoredFile)->getMorphClass(),
            null,
        )], 0, null)));
    }

    private function targetPath(string $sha256): string
    {
        $prefix = trim(Config::string('model-integrity.files.path', 'integrity-files'), '/');

        return ($prefix === '' ? '' : $prefix.'/').substr($sha256, 0, 2).'/'.substr($sha256, 2, 2).'/'.$sha256;
    }

    /**
     * @return array{string, bool} the local path and whether it is a temporary copy
     */
    private function localPath(mixed $file): array
    {
        if (is_resource($file)) {
            return [$this->copyToTemporaryFile($file), true];
        }

        $path = match (true) {
            $file instanceof SplFileInfo => $file->getRealPath(),
            is_string($file) => realpath($file),
            default => throw new InvalidArgumentException('Expected an uploaded file, a file object, a local path or a stream, got ['.get_debug_type($file).'].'),
        };

        if ($path === false || ! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('File ['.(is_string($file) ? $file : $file->getPathname()).'] does not exist or is not readable.');
        }

        return [$path, false];
    }

    /**
     * @param  resource  $stream
     */
    private function copyToTemporaryFile($stream): string
    {
        $path = tempnam(sys_get_temp_dir(), 'model-integrity-');

        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        $target = fopen($path, 'wb');

        // Like Flysystem: a stream that was just written to is read from its start.
        if (stream_get_meta_data($stream)['seekable'] && ftell($stream) !== 0) {
            rewind($stream);
        }

        try {
            if ($target === false || stream_copy_to_stream($stream, $target) === false) {
                throw new RuntimeException('Cannot copy the stream to a temporary file.');
            }
        } catch (RuntimeException $e) {
            @unlink($path);

            throw $e;
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
        }

        return $path;
    }

    private function detectMime(string $path): ?string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function connection(): Connection
    {
        $configured = config('model-integrity.connection');

        /** @var Connection */
        return DB::connection(is_string($configured) ? $configured : null);
    }
}
