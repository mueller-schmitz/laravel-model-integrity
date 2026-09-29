<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Verification;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToRetrieveMetadata;
use MuellerSchmitz\ModelIntegrity\Casts\AsIntegrityFile;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;
use MuellerSchmitz\ModelIntegrity\Events\IntegrityViolationDetected;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidEnvelopeException;
use MuellerSchmitz\ModelIntegrity\Exceptions\UnsupportedHashFormatException;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\ChainName;
use MuellerSchmitz\ModelIntegrity\Recording\ModelOptions;
use MuellerSchmitz\ModelIntegrity\Recording\SnapshotBuilder;
use Throwable;

/**
 * Verifies recorded versions, both chains, the chain heads and the current
 * state of models.
 *
 * Every check runs in a read transaction with a consistent view (REPEATABLE
 * READ), so versions recorded while a check runs are either entirely visible
 * or not at all; the recorder commits row, version and heads together.
 *
 * A broken link between two versions is reported by both neighbours with the
 * same message and merged. It invalidates the older version when the newer one
 * is intact (the newer version's pointer is covered by its own hash, so the
 * older one must have been replaced), otherwise the newer one's hash mismatch
 * already explains it.
 *
 * The public methods are the API. Protected methods are internal and may
 * change in minor releases before 1.0; do not rely on them in subclasses.
 */
class IntegrityChecker
{
    private const string UNRECORDED_MESSAGE = 'The model exists, but no version has been recorded.';

    /** Versions loaded per query; keeps memory and the number of bindings bounded. */
    private const int CHUNK = 500;

    /** @var array<int, bool> hash validity by sequence, per model check */
    private array $validHashes = [];

    /** @var array<string, string|null> problems of stored files by sha256 and depth, per check */
    private array $fileProblems = [];

    public function __construct(
        private readonly Hasher $hasher,
        private readonly SnapshotBuilder $snapshots,
    ) {}

    public function checkModel(Model $model): IntegrityResult
    {
        $this->assertTracked($model);

        $result = $this->consistently(fn (): IntegrityResult => $this->inspectModel($model));

        $this->dispatchOnFailure($result, $model);

        return $result;
    }

    /**
     * All versions of the model, oldest first. With $verify, each version's
     * isValid() tells whether the chain is intact up to that version; violations
     * dispatch IntegrityViolationDetected like checkModel().
     *
     * @return Collection<int, Version>
     */
    public function getHistory(Model $model, bool $verify = false): Collection
    {
        $this->assertTracked($model);

        $versions = $this->versionsOf($model)->get();

        if ($verify) {
            $lastValid = $this->checkModel($model)->lastValidVersion() ?? 0;
            $versions->each(fn (Version $version): Version => $version->markValidity($version->version <= $lastValid));
        }

        return $versions;
    }

    /**
     * The version that was current at the given moment. Date strings are read
     * in the application timezone.
     */
    public function versionAt(Model $model, DateTimeInterface|string $date): ?Version
    {
        $this->assertTracked($model);

        $moment = $date instanceof DateTimeInterface ? CarbonImmutable::instance($date) : CarbonImmutable::parse($date);

        return $this->versionsOf($model)
            ->where('created_at', '<=', $moment->utc()->format('Y-m-d H:i:s.u'))
            ->reorder('version', 'desc')
            ->first();
    }

    /**
     * Checks every recorded model of the class, including deleted ones, and
     * reports rows without any recorded version.
     *
     * The recorded keys of the type are held in memory for the second part.
     *
     * @param  class-string<Model>  $class
     * @param  bool  $stopOnFirstFailure  stop after the first model with violations
     */
    public function checkType(string $class, bool $stopOnFirstFailure = false): IntegrityResult
    {
        if (! $this->isTracked($class)) {
            throw IntegrityConfigurationException::notTracked($class);
        }

        $result = $this->consistently(fn (): IntegrityResult => $this->inspectType($class, null, $stopOnFirstFailure));

        $this->dispatchOnFailure($result, null);

        return $result;
    }

    /**
     * Checks the global chain and every recorded model of every type.
     *
     * Models of a class without any recorded version are not discovered here;
     * use checkType() for them.
     *
     * @param  bool  $stopOnFirstFailure  stop after a broken chain or the first model with violations
     * @param  (Closure(string): void)|null  $progress  called with a description before each step
     */
    public function checkAll(bool $stopOnFirstFailure = false, ?Closure $progress = null): IntegrityResult
    {
        $result = $this->consistently(function () use ($stopOnFirstFailure, $progress): IntegrityResult {
            $progress?->__invoke('Checking the global chain');
            $chain = $this->inspectChain();

            if ($stopOnFirstFailure && $chain->fails()) {
                return $chain;
            }

            $results = [$chain];

            foreach ($this->recordedTypes() as $type) {
                $progress?->__invoke('Checking '.(Relation::getMorphedModel($type) ?? $type));
                $results[] = $this->inspectRecordedType($type, $stopOnFirstFailure);

                if ($stopOnFirstFailure && end($results)->fails()) {
                    break;
                }
            }

            return IntegrityResult::combine($results, $chain->checkedVersions());
        });

        $this->dispatchOnFailure($result, null);

        return $result;
    }

    /**
     * Verifies the global chain over all models: hashes, a gapless sequence,
     * the links between consecutive versions and the head.
     */
    public function checkChain(): IntegrityResult
    {
        $result = $this->consistently(fn (): IntegrityResult => $this->inspectChain());

        $this->dispatchOnFailure($result, null);

        return $result;
    }

    /**
     * Checks every stored file: present on its disk with the recorded size, and
     * with $contents also its content hash. Hashing reads every file, so run
     * it less often than the other checks. checkedVersions() counts the files.
     *
     * Runs without a read transaction: file records are append-only, and a
     * transaction held open while hashing large amounts of data would keep an
     * old read view alive for hours.
     */
    public function checkFiles(bool $contents = true): IntegrityResult
    {
        $this->fileProblems = [];
        $errors = [];
        $count = 0;

        foreach (StoredFile::query()->lazyById(self::CHUNK) as $file) {
            $count++;
            $message = $this->storedFileProblem($file, $contents);

            if ($message !== null) {
                $errors[] = $this->error(
                    IntegrityErrorType::FileMismatch,
                    "Stored file [{$file->sha256}] {$message}.",
                    [$file->getMorphClass(), $this->key($file)],
                );
            }
        }

        $result = new IntegrityResult($errors, $count, null);

        $this->dispatchOnFailure($result, null);

        return $result;
    }

    /**
     * The stored model with the given key, or a key-only instance if the row
     * no longer exists, so deleted models can be checked as well.
     *
     * @param  class-string<Model>  $class
     */
    public function findModel(string $class, int|string $id): Model
    {
        if (! $this->isTracked($class)) {
            throw IntegrityConfigurationException::notTracked($class);
        }

        $prototype = new $class;

        return $class::query()->withoutGlobalScopes()->find($id) ?? $this->keyOnlyInstance($prototype, $id);
    }

    /**
     * Runs the callback in a read transaction with a consistent view. Inside a
     * caller's transaction the caller's isolation level applies.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    protected function consistently(Closure $callback): mixed
    {
        $this->fileProblems = [];
        $connection = DB::connection($this->connection());
        $driver = $connection->getDriverName();
        $outermost = $connection->transactionLevel() === 0;

        // MySQL and MariaDB apply the level to the next transaction only.
        if ($outermost && in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $connection->transaction(function () use ($connection, $driver, $outermost, $callback): mixed {
            // PostgreSQL defaults to READ COMMITTED; set before the first query.
            if ($outermost && $driver === 'pgsql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            return $callback();
        });
    }

    /**
     * A recorded morph type: its class, or the reason it cannot be checked.
     */
    private function inspectRecordedType(string $type, bool $stopOnFirstFailure): IntegrityResult
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! $this->isTracked($class)) {
            return new IntegrityResult([$this->error(
                IntegrityErrorType::Unverifiable,
                "Model class [{$class}] does not exist or does not use HasIntegrity; its versions and state were not checked.",
                [$type, null],
            )], 0, null);
        }

        $currentType = (new $class)->getMorphClass();

        if ($currentType === $type) {
            return $this->inspectType($class, null, $stopOnFirstFailure);
        }

        // Versions were recorded under a former morph class (e.g. before a morph
        // map was introduced). Their chains are checked under that type.
        $results = [
            new IntegrityResult([$this->error(
                IntegrityErrorType::Unverifiable,
                "Versions of [{$class}] were recorded under morph class [{$type}], but the model now uses [{$currentType}]. Their chains were checked; new versions start a separate history.",
                [$type, null],
            )], 0, null),
            $this->inspectType($class, $type, $stopOnFirstFailure),
        ];

        return IntegrityResult::combine($results, $results[1]->checkedVersions());
    }

    /**
     * @param  class-string<Model>  $class
     * @param  string|null  $type  the morph type the versions were recorded under, if it differs from the model's
     */
    protected function inspectType(string $class, ?string $type, bool $stopOnFirstFailure = false): IntegrityResult
    {
        $prototype = new $class;
        $type ??= $prototype->getMorphClass();
        $keyName = $prototype->getKeyName();
        $castKey = fn (string $id): int|string => in_array($prototype->getKeyType(), ['int', 'integer'], true) ? (int) $id : $id;

        /** @var list<string> $ids */
        $ids = Version::query()
            ->where('versionable_type', $type)
            ->distinct()
            ->pluck('versionable_id')
            ->map(fn (mixed $id): string => is_scalar($id) ? (string) $id : '')
            ->all();

        $errors = [];
        $checked = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $models = $class::query()
                ->withoutGlobalScopes()
                ->whereIn($keyName, array_map($castKey, $chunk))
                ->get()
                ->keyBy(fn (Model $model): string => $this->key($model));

            foreach ($chunk as $id) {
                $model = $models->get($id) ?? $this->keyOnlyInstance($prototype, $castKey($id));

                $result = $this->inspectModel($model, $type);
                $checked += $result->checkedVersions();
                array_push($errors, ...$result->errors()->all());

                if ($stopOnFirstFailure && $result->fails()) {
                    return new IntegrityResult($errors, $checked, null);
                }
            }
        }

        // Rows without any version are only meaningful under the model's current type.
        if ($type === $prototype->getMorphClass()) {
            $recorded = array_flip($ids);

            foreach ($class::query()->withoutGlobalScopes()->select($keyName)->lazyById(1000, $keyName) as $model) {
                if (! isset($recorded[$this->key($model)])) {
                    $errors[] = $this->error(IntegrityErrorType::StateDrift, self::UNRECORDED_MESSAGE, [$type, $this->key($model)]);

                    if ($stopOnFirstFailure) {
                        break;
                    }
                }
            }
        }

        return new IntegrityResult($errors, $checked, null);
    }

    protected function inspectChain(): IntegrityResult
    {
        $head = $this->head(ChainName::GLOBAL);
        $errors = [];
        $count = 0;
        $previous = null;

        // Batched by sequence: memory stays bounded and only the previous version is held.
        foreach (Version::query()->orderBy('sequence')->lazyById(self::CHUNK, 'sequence') as $version) {
            $count++;
            $subject = [$version->versionable_type, $version->versionable_id];
            $hashIsValid = $this->computeHashValidity($version);

            if ($version->sequence > ($previous->sequence ?? 0) + 1) {
                $errors[] = $this->gapError([null, null], ($previous->sequence ?? 0) + 1, $version->sequence - 1);
            }

            if (! $hashIsValid) {
                $errors[] = $this->error(IntegrityErrorType::HashMismatch, $this->hashMessage($version), $subject, $version->version, $version->sequence);
            }

            if ($version->sequence === 1 && $version->global_prev_hash !== null) {
                $errors[] = $this->globalLinkError($subject, $version, null);
            } elseif ($previous !== null && $previous->sequence === $version->sequence - 1 && $version->global_prev_hash !== $previous->hash) {
                $errors[] = $hashIsValid
                    ? $this->globalLinkError([$previous->versionable_type, $previous->versionable_id], $version, $previous->version)
                    : $this->globalLinkError([null, null], $version, null);
            }

            $previous = $version;
        }

        $lastSequence = $previous->sequence ?? 0;

        if ($head === null) {
            $errors[] = $this->error(
                IntegrityErrorType::TruncatedChain,
                "The global chain head is missing; the last stored version has sequence {$lastSequence}.",
                [null, null],
                null,
                $lastSequence,
            );
        } elseif ($head['sequence'] > $lastSequence) {
            $errors[] = $this->truncatedError([null, null], $head['sequence'], $lastSequence);
        } elseif ($head['sequence'] < $lastSequence || $head['hash'] !== $previous?->hash) {
            $errors[] = $this->headError([null, null], $head, $lastSequence);
        }

        return new IntegrityResult($errors, $count, null);
    }

    /**
     * @param  string|null  $type  the morph type the versions were recorded under, if it differs from the model's
     */
    protected function inspectModel(Model $model, ?string $type = null): IntegrityResult
    {
        $this->validHashes = [];

        $type ??= $model->getMorphClass();
        $id = $this->key($model);
        $subject = [$type, $id];
        $head = $this->head(ChainName::GLOBAL);
        $maxSequence = $this->maxSequence();

        $errors = [];
        $invalid = [];
        $count = 0;
        $previous = null;
        $lastValid = null;
        $chainIntact = true;
        $fileAttributes = $this->fileAttributes($model);
        /** @var array<string, array{string, int}> $referencedFiles sha256 => [attribute, first version] */
        $referencedFiles = [];

        if ($head === null) {
            $errors[] = $this->error(IntegrityErrorType::TruncatedChain, 'The global chain head is missing.', $subject);
        }

        // Versions and sequences grow together, so batching by sequence yields version order.
        $versions = $this->versionsOf($model, $type)->reorder()->lazyById(self::CHUNK, 'sequence');

        foreach ($versions->chunk(self::CHUNK) as $lazyChunk) {
            $chunk = $lazyChunk->collect();
            $count += $chunk->count();
            $neighbours = $this->neighboursOf($chunk);

            foreach ($chunk as $version) {
                $this->checkVersionOfModel($version, $previous, $subject, $errors, $invalid);
                $this->checkGlobalNeighbours($version, $neighbours, $subject, $head, $maxSequence, $errors, $invalid);

                $chainIntact = $chainIntact
                    && $version->version === ($previous->version ?? 0) + 1
                    && ! isset($invalid[$version->version]);

                if ($chainIntact) {
                    $lastValid = $version->version;
                }

                foreach ($fileAttributes as $attribute) {
                    $sha256 = $version->snapshot[$attribute] ?? null;

                    if (is_string($sha256) && ! isset($referencedFiles[$sha256])) {
                        $referencedFiles[$sha256] = [$attribute, $version->version];
                    }
                }

                $previous = $version;
            }
        }

        // Versions blamed for a broken global link are invalid as well.
        $lastValid = $this->lastValidBelow($lastValid, $errors, $invalid);

        $this->checkModelHead($type, $id, $previous, $subject, $errors);
        $this->checkState($model, $previous, $subject, $errors);
        $this->checkReferencedFiles($referencedFiles, $subject, $errors);

        return new IntegrityResult($errors, $count, $lastValid);
    }

    /**
     * Hash, version numbering and the per-model link of one version.
     *
     * @param  array{string|null, string|null}  $subject
     * @param  list<IntegrityError>  $errors
     * @param  array<int, true>  $invalid  invalid version numbers
     */
    private function checkVersionOfModel(Version $version, ?Version $previous, array $subject, array &$errors, array &$invalid): void
    {
        $expected = $previous === null ? 1 : $previous->version + 1;

        if (! $this->hashIsValid($version)) {
            $errors[] = $this->error(IntegrityErrorType::HashMismatch, $this->hashMessage($version), $subject, $version->version, $version->sequence);
            $invalid[$version->version] = true;
        }

        if ($version->version !== $expected) {
            $errors[] = $this->error(
                IntegrityErrorType::VersionGap,
                "Expected version {$expected}, found version {$version->version}.",
                $subject,
                $version->version,
                $version->sequence,
            );
        } elseif ($version->prev_hash !== $previous?->hash) {
            // Not checked across a version gap, which is reported already.
            $blamed = $previous !== null && $this->hashIsValid($version) ? $previous : $version;
            $errors[] = $this->error(
                IntegrityErrorType::BrokenChain,
                "prev_hash of version {$version->version} does not reference version ".($version->version - 1).'.',
                $subject,
                $blamed->version,
                $version->sequence,
            );
            $invalid[$blamed->version] = true;
        }
    }

    /**
     * The global chain around one version of the model, and the head for the last entry.
     *
     * @param  Collection<int, Version>  $neighbours  versions by sequence: the chunk and its neighbours
     * @param  array{string|null, string|null}  $subject
     * @param  array{sequence: int, hash: string|null}|null  $head
     * @param  list<IntegrityError>  $errors
     * @param  array<int, true>  $invalid
     */
    private function checkGlobalNeighbours(Version $version, Collection $neighbours, array $subject, ?array $head, int $maxSequence, array &$errors, array &$invalid): void
    {
        $sequence = $version->sequence;

        // Link to the predecessor.
        if ($sequence === 1) {
            if ($version->global_prev_hash !== null) {
                $errors[] = $this->globalLinkError($subject, $version, null);
            }
        } elseif (($predecessor = $neighbours->get($sequence - 1)) === null) {
            $errors[] = $this->gapError($subject, $sequence - 1, $sequence - 1);
        } elseif ($version->global_prev_hash !== $predecessor->hash) {
            $errors[] = $this->globalLinkError($subject, $version, $this->blame($predecessor, $version, $subject));
        }

        // Link to the successor, or the head for the last entry.
        if (($successor = $neighbours->get($sequence + 1)) !== null) {
            if ($successor->global_prev_hash !== $version->hash) {
                $blamed = $this->blame($version, $successor, $subject);
                $errors[] = $this->globalLinkError($subject, $successor, $blamed);

                if ($blamed !== null) {
                    $invalid[$blamed] = true;
                }
            }
        } elseif ($head === null) {
            // Reported once by the caller.
        } elseif ($head['sequence'] > $sequence) {
            $errors[] = $maxSequence === $sequence
                ? $this->truncatedError($subject, $head['sequence'], $sequence)
                : $this->gapError($subject, $sequence + 1, $sequence + 1);
        } elseif ($head['sequence'] < $sequence || $head['hash'] !== $version->hash) {
            $errors[] = $this->headError($subject, $head, $sequence);
        }
    }

    /**
     * The chunk's versions plus the versions directly before and after each of
     * them, keyed by sequence. Two bindings per version keep the query small.
     *
     * @param  Collection<int, Version>  $chunk
     * @return Collection<int, Version>
     */
    private function neighboursOf(Collection $chunk): Collection
    {
        $sequences = $chunk->map(fn (Version $version): int => $version->sequence);
        $wanted = $sequences->map(fn (int $s): int => $s - 1)
            ->merge($sequences->map(fn (int $s): int => $s + 1))
            ->diff($sequences)
            ->filter(fn (int $s): bool => $s > 0)
            ->unique()
            ->values();

        /** @var Collection<int, Version> $neighbours */
        $neighbours = $wanted->isEmpty()
            ? new Collection
            : Version::query()->whereIn('sequence', $wanted->all())->get();

        return $neighbours->merge($chunk)->keyBy('sequence');
    }

    /**
     * The version number of the checked model to blame for a broken global
     * link from $older to $newer, or null if the newer version explains it or
     * the older one belongs to another model.
     *
     * @param  array{string|null, string|null}  $subject
     */
    private function blame(Version $older, Version $newer, array $subject): ?int
    {
        $ownOlder = $older->versionable_type === $subject[0] && $older->versionable_id === $subject[1];

        return $ownOlder && $this->hashIsValid($newer) ? $older->version : null;
    }

    /**
     * The highest version n for which versions 1..n carry no violation,
     * including versions blamed for broken global links.
     *
     * @param  list<IntegrityError>  $errors
     * @param  array<int, true>  $invalid
     */
    private function lastValidBelow(?int $lastValid, array $errors, array $invalid): ?int
    {
        foreach ($errors as $error) {
            if ($error->type === IntegrityErrorType::BrokenChain && $error->version !== null) {
                $invalid[$error->version] = true;
            }
        }

        $invalidVersions = array_keys($invalid);
        sort($invalidVersions);
        $firstInvalid = $invalidVersions[0] ?? null;

        if ($firstInvalid !== null && ($lastValid === null || $firstInvalid <= $lastValid)) {
            $lastValid = $firstInvalid > 1 ? $firstInvalid - 1 : null;
        }

        return $lastValid;
    }

    /**
     * The model's head row must point at its last stored version.
     *
     * @param  array{string|null, string|null}  $subject
     * @param  list<IntegrityError>  $errors
     */
    private function checkModelHead(string $type, string $id, ?Version $last, array $subject, array &$errors): void
    {
        $head = $this->head(ChainName::for($type, $id));

        $message = match (true) {
            $head === null && $last === null => null,
            $head === null => "The model head is missing; the last stored version is {$last->version}.",
            $last === null => "The model head is at version {$head['sequence']}, but no version is stored.",
            $head['sequence'] !== $last->version || $head['hash'] !== $last->hash => "The model head (version {$head['sequence']}) does not match the last stored version ({$last->version}).",
            default => null,
        };

        if ($message !== null) {
            $errors[] = $this->error(IntegrityErrorType::TruncatedChain, $message, $subject, null, $last?->sequence);
        }
    }

    /**
     * Files referenced by any version must be recorded and present on their
     * disk with the recorded size. Contents are hashed by checkFiles() only.
     *
     * @param  array<string, array{string, int}>  $references  sha256 => [attribute, first version]
     * @param  array{string|null, string|null}  $subject
     * @param  list<IntegrityError>  $errors
     */
    private function checkReferencedFiles(array $references, array $subject, array &$errors): void
    {
        if ($references === []) {
            return;
        }

        $files = StoredFile::query()->whereIn('sha256', array_keys($references))->get()->keyBy('sha256');

        foreach ($references as $sha256 => [$attribute, $version]) {
            $file = $files->get($sha256);
            $where = "[{$attribute}] of version {$version} references file [{$sha256}]";

            $message = $file === null
                ? "{$where}, but there is no stored file with this hash."
                : (($problem = $this->storedFileProblem($file, false)) === null ? null : "{$where}, which {$problem}.");

            if ($message !== null) {
                $errors[] = $this->error(IntegrityErrorType::FileMismatch, $message, $subject);
            }
        }
    }

    /**
     * What is wrong with a stored file on its disk, or null if nothing is.
     */
    /**
     * Cached per file and check: shared files are looked up on the disk once.
     */
    private function storedFileProblem(StoredFile $file, bool $contents): ?string
    {
        $key = $file->sha256.($contents ? ':content' : ':size');

        if (! array_key_exists($key, $this->fileProblems)) {
            $this->fileProblems[$key] = $this->findStoredFileProblem($file, $contents);
        }

        return $this->fileProblems[$key];
    }

    /**
     * Disk errors (e.g. a disk that is no longer configured, network errors)
     * are reported as findings instead of aborting the check.
     */
    private function findStoredFileProblem(StoredFile $file, bool $contents): ?string
    {
        try {
            $disk = Storage::disk($file->disk);

            // One metadata request; it fails for a missing file.
            try {
                $size = $disk->size($file->path);
            } catch (UnableToRetrieveMetadata) {
                return "is missing on disk [{$file->disk}]";
            }

            if ($size !== $file->size) {
                return "has {$size} bytes on disk, but {$file->size} were recorded";
            }

            if ($contents) {
                $stream = $disk->readStream($file->path);

                if (! is_resource($stream)) {
                    return "cannot be read from disk [{$file->disk}]";
                }

                $context = hash_init('sha256');
                hash_update_stream($context, $stream);
                fclose($stream);

                if (! hash_equals($file->sha256, hash_final($context))) {
                    return 'has a content that does not match its hash';
                }
            }
        } catch (Throwable $e) {
            return "cannot be checked on disk [{$file->disk}]: ".$e->getMessage();
        }

        return null;
    }

    /**
     * Attributes cast with AsIntegrityFile.
     *
     * @return list<string>
     */
    private function fileAttributes(Model $model): array
    {
        $attributes = [];

        foreach ($model->getCasts() as $attribute => $cast) {
            if (explode(':', $cast, 2)[0] === AsIntegrityFile::class) {
                $attributes[] = $attribute;
            }
        }

        return $attributes;
    }

    /**
     * Compares the current row with the last snapshot.
     *
     * @param  array{string|null, string|null}  $subject
     * @param  list<IntegrityError>  $errors
     */
    private function checkState(Model $model, ?Version $last, array $subject, array &$errors): void
    {
        $exists = $model->getConnection()
            ->table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->exists();

        $drift = fn (string $message): IntegrityError => $this->error(IntegrityErrorType::StateDrift, $message, $subject);

        if ($last === null) {
            if ($exists) {
                $errors[] = $drift(self::UNRECORDED_MESSAGE);
            }

            return;
        }

        $hardDeleted = $last->event === 'force_deleted'
            || ($last->event === 'deleted' && ! in_array(SoftDeletes::class, class_uses_recursive($model), true));

        if ($hardDeleted) {
            if ($exists) {
                $errors[] = $drift("The model exists, but version {$last->version} recorded its deletion.");
            }

            return;
        }

        if (! $exists) {
            $errors[] = $drift("The model does not exist, but no deletion has been recorded after version {$last->version}.");

            return;
        }

        $options = ModelOptions::of($model);
        $current = $this->snapshots->build($model, $options->except, $options->relations);

        if ($current !== $last->snapshot) {
            $changed = $this->changedKeys($last->snapshot, $current);
            $errors[] = $drift('The current state differs from version '.$last->version.' in ['.implode(', ', $changed).'].');
        }
    }

    /**
     * @param  array<string, mixed>  $recorded
     * @param  array<string, mixed>  $current
     * @return list<string>
     */
    private function changedKeys(array $recorded, array $current): array
    {
        $keys = array_unique(array_merge(array_keys($recorded), array_keys($current)));

        $changed = array_values(array_filter(
            $keys,
            fn (string $key): bool => ! array_key_exists($key, $recorded)
                || ! array_key_exists($key, $current)
                || $recorded[$key] !== $current[$key],
        ));

        sort($changed);

        return $changed;
    }

    protected function hashIsValid(Version $version): bool
    {
        return $this->validHashes[$version->sequence] ??= $this->computeHashValidity($version);
    }

    private function computeHashValidity(Version $version): bool
    {
        try {
            return hash_equals($version->hash, $this->hasher->hash($version->toEnvelope()));
        } catch (UnsupportedHashFormatException|InvalidEnvelopeException) {
            return false;
        }
    }

    protected function hashMessage(Version $version): string
    {
        try {
            Hasher::fields($version->hash_format);
        } catch (UnsupportedHashFormatException) {
            return "Unsupported hash format {$version->hash_format}.";
        }

        return 'The stored hash does not match the stored content.';
    }

    /**
     * @param  array{string|null, string|null}  $subject
     */
    protected function globalLinkError(array $subject, Version $newer, ?int $blamedVersion): IntegrityError
    {
        $message = $newer->sequence === 1
            ? 'global_prev_hash of sequence 1 must be empty.'
            : "global_prev_hash of sequence {$newer->sequence} does not reference sequence ".($newer->sequence - 1).'.';

        return $this->error(IntegrityErrorType::BrokenChain, $message, $subject, $blamedVersion, $newer->sequence);
    }

    /**
     * A range of missing sequences, reported as one error.
     *
     * @param  array{string|null, string|null}  $subject
     */
    protected function gapError(array $subject, int $from, int $to): IntegrityError
    {
        $message = $from === $to ? "Sequence {$from} is missing." : "Sequences {$from} to {$to} are missing.";

        return $this->error(IntegrityErrorType::SequenceGap, $message, $subject, null, $from);
    }

    /**
     * @param  array{string|null, string|null}  $subject
     */
    protected function truncatedError(array $subject, int $headSequence, int $lastSequence): IntegrityError
    {
        return $this->error(
            IntegrityErrorType::TruncatedChain,
            "The chain head is at sequence {$headSequence}, but the last stored version has sequence {$lastSequence}.",
            $subject,
            null,
            $lastSequence + 1,
        );
    }

    /**
     * @param  array{string|null, string|null}  $subject
     * @param  array{sequence: int, hash: string|null}  $head
     */
    protected function headError(array $subject, array $head, int $lastSequence): IntegrityError
    {
        return $this->error(
            IntegrityErrorType::TruncatedChain,
            "The chain head (sequence {$head['sequence']}) does not match the last stored version (sequence {$lastSequence}).",
            $subject,
            null,
            $lastSequence,
        );
    }

    /**
     * @param  array{string|null, string|null}  $subject
     */
    protected function error(IntegrityErrorType $type, string $message, array $subject, ?int $version = null, ?int $sequence = null): IntegrityError
    {
        return new IntegrityError($type, $message, $subject[0], $subject[1], $version, $sequence);
    }

    /**
     * @return array{sequence: int, hash: string|null}|null null when the head row is missing
     */
    protected function head(string $chain): ?array
    {
        $head = DB::connection($this->connection())
            ->table(Config::string('model-integrity.tables.heads'))
            ->where('chain', $chain)
            ->first();

        if ($head === null) {
            return null;
        }

        return [
            'sequence' => is_numeric($head->sequence) ? (int) $head->sequence : 0,
            'hash' => is_string($head->hash) ? $head->hash : null,
        ];
    }

    protected function maxSequence(): int
    {
        $max = Version::query()->max('sequence');

        return is_numeric($max) ? (int) $max : 0;
    }

    /**
     * @return list<string>
     */
    private function recordedTypes(): array
    {
        $types = [];

        foreach (Version::query()->distinct()->orderBy('versionable_type')->pluck('versionable_type') as $type) {
            if (is_string($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    protected function connection(): ?string
    {
        $connection = config('model-integrity.connection');

        return is_string($connection) ? $connection : null;
    }

    protected function dispatchOnFailure(IntegrityResult $result, ?Model $model): void
    {
        if ($result->fails()) {
            event(new IntegrityViolationDetected($result, $model));
        }
    }

    protected function assertTracked(Model $model): void
    {
        if (! $this->isTracked($model::class)) {
            throw IntegrityConfigurationException::notTracked($model::class);
        }
    }

    protected function isTracked(string $class): bool
    {
        return in_array(HasIntegrity::class, class_uses_recursive($class), true);
    }

    protected function keyOnlyInstance(Model $prototype, int|string $id): Model
    {
        return $prototype->newInstance()->forceFill([$prototype->getKeyName() => $id]);
    }

    /**
     * @return Builder<Version>
     */
    protected function versionsOf(Model $model, ?string $type = null): Builder
    {
        return Version::query()
            ->where('versionable_type', $type ?? $model->getMorphClass())
            ->where('versionable_id', $this->key($model))
            ->orderBy('version');
    }

    protected function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
