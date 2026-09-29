<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Files;

use finfo;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Recording\ChainName;
use RuntimeException;
use SplFileInfo;

/**
 * Stores files content-addressed under their SHA-256 hash. Identical content
 * is stored once; an existing file is never overwritten.
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

            return $this->persist($path, $sha256, $size, $mime ?? $this->detectMime($path));
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

    private function persist(string $path, string $sha256, int $size, ?string $mime): StoredFile
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $path, $sha256, $size, $mime): StoredFile {
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

            $diskName = Config::string('model-integrity.files.disk', 'local');
            $target = $this->targetPath($sha256);
            $disk = Storage::disk($diskName);

            // Content-addressed: an existing file with this name must not be replaced.
            if (! $disk->exists($target)) {
                $stream = fopen($path, 'rb');

                if ($stream === false || ! $disk->writeStream($target, $stream)) {
                    throw new RuntimeException("Cannot write file [{$target}] to disk [{$diskName}].");
                }

                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return StoredFile::query()->create([
                'sha256' => $sha256,
                'disk' => $diskName,
                'path' => $target,
                'size' => $size,
                'mime' => $mime,
            ]);
        });
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
            $path = tempnam(sys_get_temp_dir(), 'model-integrity-');
            $target = $path === false ? false : fopen($path, 'wb');

            if ($path === false || $target === false || stream_copy_to_stream($file, $target) === false) {
                throw new RuntimeException('Cannot copy the stream to a temporary file.');
            }

            fclose($target);

            return [$path, true];
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
