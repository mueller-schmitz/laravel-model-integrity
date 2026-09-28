<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Verification;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;
use MuellerSchmitz\ModelIntegrity\Events\IntegrityViolationDetected;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidEnvelopeException;
use MuellerSchmitz\ModelIntegrity\Exceptions\UnsupportedHashFormatException;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\ModelOptions;
use MuellerSchmitz\ModelIntegrity\Recording\SnapshotBuilder;

/**
 * Verifies recorded versions, both chains, the chain head and the current
 * state of models.
 *
 * A broken link between two versions is reported by both neighbours with the
 * same message and merged. It invalidates the older version when the newer one
 * is intact (the newer version's pointer is covered by its own hash, so the
 * older one must have been replaced), otherwise the newer one's hash mismatch
 * already explains it.
 */
class IntegrityChecker
{
    private const string UNRECORDED_MESSAGE = 'The model exists, but no version has been recorded.';

    /** @var array<int, bool> hash validity by sequence, per check */
    private array $validHashes = [];

    public function __construct(
        private readonly Hasher $hasher,
        private readonly SnapshotBuilder $snapshots,
    ) {}

    public function checkModel(Model $model): IntegrityResult
    {
        $result = $this->inspectModel($model);

        $this->dispatchOnFailure($result, $model);

        return $result;
    }

    /**
     * All versions of the model, oldest first. With $verify, each version's
     * isValid() tells whether the chain is intact up to that version.
     *
     * @return Collection<int, Version>
     */
    public function getHistory(Model $model, bool $verify = false): Collection
    {
        $this->assertTracked($model);

        $versions = $this->versionsOf($model)->get();

        if ($verify) {
            $lastValid = $this->inspectModel($model)->lastValidVersion() ?? 0;
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
        $result = $this->inspectType($class, $stopOnFirstFailure);

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
     */
    public function checkAll(bool $stopOnFirstFailure = false): IntegrityResult
    {
        $chain = $this->inspectChain();
        $results = [$chain];

        if ($stopOnFirstFailure && $chain->fails()) {
            $this->dispatchOnFailure($chain, null);

            return $chain;
        }

        foreach (Version::query()->distinct()->orderBy('versionable_type')->pluck('versionable_type') as $type) {
            $type = is_string($type) ? $type : '';
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! $this->isTracked($class)) {
                $results[] = new IntegrityResult([$this->error(
                    IntegrityErrorType::StateDrift,
                    "Model class [{$class}] does not exist or does not use HasIntegrity; its state was not checked.",
                    [$type, null],
                )], 0, null);
            } else {
                $results[] = $this->inspectType($class, $stopOnFirstFailure);
            }

            if ($stopOnFirstFailure && end($results)->fails()) {
                break;
            }
        }

        $result = IntegrityResult::combine($results, $chain->checkedVersions());

        $this->dispatchOnFailure($result, null);

        return $result;
    }

    /**
     * @param  class-string<Model>  $class
     */
    protected function inspectType(string $class, bool $stopOnFirstFailure = false): IntegrityResult
    {
        if (! $this->isTracked($class)) {
            throw IntegrityConfigurationException::notTracked($class);
        }

        $prototype = new $class;
        $keyName = $prototype->getKeyName();
        $castKey = fn (string $id): int|string => in_array($prototype->getKeyType(), ['int', 'integer'], true) ? (int) $id : $id;

        /** @var list<string> $ids */
        $ids = Version::query()
            ->where('versionable_type', $prototype->getMorphClass())
            ->distinct()
            ->pluck('versionable_id')
            ->map(fn (mixed $id): string => is_scalar($id) ? (string) $id : '')
            ->all();

        $results = [];
        $checked = 0;

        foreach (array_chunk($ids, 500) as $chunk) {
            $models = $class::query()
                ->withoutGlobalScopes()
                ->whereIn($keyName, array_map($castKey, $chunk))
                ->get()
                ->keyBy(fn (Model $model): string => $this->key($model));

            foreach ($chunk as $id) {
                $model = $models->get($id) ?? $this->keyOnlyInstance($prototype, $castKey($id));

                $result = $this->inspectModel($model);
                $results[] = $result;
                $checked += $result->checkedVersions();

                if ($stopOnFirstFailure && $result->fails()) {
                    return IntegrityResult::combine($results, $checked);
                }
            }
        }

        $recorded = array_flip($ids);
        $unrecorded = [];

        foreach ($class::query()->withoutGlobalScopes()->select($keyName)->lazyById(1000, $keyName) as $model) {
            if (! isset($recorded[$this->key($model)])) {
                $unrecorded[] = $this->error(
                    IntegrityErrorType::StateDrift,
                    self::UNRECORDED_MESSAGE,
                    [$prototype->getMorphClass(), $this->key($model)],
                );

                if ($stopOnFirstFailure) {
                    break;
                }
            }
        }

        $results[] = new IntegrityResult($unrecorded, 0, null);

        return IntegrityResult::combine($results, $checked);
    }

    /**
     * Verifies the global chain over all models: hashes, a gapless sequence,
     * the links between consecutive versions and the head.
     */
    public function checkChain(): IntegrityResult
    {
        $result = $this->inspectChain();

        $this->dispatchOnFailure($result, null);

        return $result;
    }

    protected function inspectChain(): IntegrityResult
    {
        $errors = [];
        $count = 0;
        $previous = null;

        // A cursor keeps memory constant; only the previous version is held.
        foreach (Version::query()->orderBy('sequence')->cursor() as $version) {
            $count++;
            $subject = [$version->versionable_type, $version->versionable_id];
            $hashIsValid = $this->computeHashValidity($version);

            for ($missing = ($previous->sequence ?? 0) + 1; $missing < $version->sequence; $missing++) {
                $errors[] = $this->gapError([null, null], $missing);
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

        $head = $this->head();
        $lastSequence = $previous->sequence ?? 0;

        if ($head['sequence'] > $lastSequence) {
            $errors[] = $this->truncatedError([null, null], $head['sequence'], $lastSequence);
        } elseif ($head['sequence'] < $lastSequence || $head['hash'] !== $previous?->hash) {
            $errors[] = $this->headError([null, null], $head, $lastSequence);
        }

        return new IntegrityResult($errors, $count, null);
    }

    protected function inspectModel(Model $model): IntegrityResult
    {
        $this->assertTracked($model);
        $this->validHashes = [];

        $versions = $this->versionsOf($model)->get();

        $subject = [$model->getMorphClass(), $this->key($model)];
        $errors = [];
        $invalid = [];

        $this->checkVersionsOfModel($versions, $subject, $errors, $invalid);
        $this->checkGlobalNeighbours($versions, $subject, $errors, $invalid);
        $this->checkState($model, $versions->last(), $subject, $errors);

        return new IntegrityResult($errors, $versions->count(), $this->lastValidVersion($versions, $invalid));
    }

    /**
     * Hashes, version numbering and the per-model chain.
     *
     * @param  Collection<int, Version>  $versions
     * @param  array{string|null, string|null}  $subject
     * @param  list<IntegrityError>  $errors
     * @param  array<int, true>  $invalid  invalid version numbers
     */
    private function checkVersionsOfModel(Collection $versions, array $subject, array &$errors, array &$invalid): void
    {
        $previous = null;

        foreach ($versions as $version) {
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

            $previous = $version;
        }
    }

    /**
     * The global chain around each version of the model, and the head.
     *
     * @param  Collection<int, Version>  $versions
     * @param  array{string|null, string|null}  $subject
     * @param  list<IntegrityError>  $errors
     * @param  array<int, true>  $invalid
     */
    private function checkGlobalNeighbours(Collection $versions, array $subject, array &$errors, array &$invalid): void
    {
        if ($versions->isEmpty()) {
            return;
        }

        $sequences = $versions->map(fn (Version $version): int => $version->sequence);
        $neighbours = Version::query()
            ->whereIn('sequence', $sequences->map(fn (int $s): int => $s - 1)->merge($sequences->map(fn (int $s): int => $s + 1))->unique()->values())
            ->get()
            ->keyBy('sequence');
        $own = $versions->keyBy('sequence');
        $all = $neighbours->union($own);

        $head = $this->head();
        $maxSequence = $this->maxSequence();

        foreach ($versions as $version) {
            $sequence = $version->sequence;

            // Link to the predecessor.
            if ($sequence === 1) {
                if ($version->global_prev_hash !== null) {
                    $errors[] = $this->globalLinkError($subject, $version, null);
                }
            } elseif (($predecessor = $all->get($sequence - 1)) === null) {
                $errors[] = $this->gapError($subject, $sequence - 1);
            } elseif ($version->global_prev_hash !== $predecessor->hash) {
                $errors[] = $this->globalLinkError($subject, $version, $this->blame($predecessor, $version, $own));
            }

            // Link to the successor, or the head for the last entry.
            if (($successor = $all->get($sequence + 1)) !== null) {
                if ($successor->global_prev_hash !== $version->hash) {
                    $errors[] = $this->globalLinkError($subject, $successor, $this->blame($version, $successor, $own));
                }
            } elseif ($head['sequence'] > $sequence) {
                $errors[] = $maxSequence === $sequence
                    ? $this->truncatedError($subject, $head['sequence'], $sequence)
                    : $this->gapError($subject, $sequence + 1);
            } elseif ($head['sequence'] < $sequence || $head['hash'] !== $version->hash) {
                $errors[] = $this->headError($subject, $head, $sequence);
            }
        }

        // Blamed versions of broken global links are invalid.
        foreach ($errors as $error) {
            if ($error->type === IntegrityErrorType::BrokenChain && $error->version !== null) {
                $invalid[$error->version] = true;
            }
        }
    }

    /**
     * The version number of this model to blame for a broken global link from
     * $older to $newer, or null if the newer version explains it or the older
     * one belongs to another model.
     *
     * @param  Collection<int, Version>  $own  versions of the checked model by sequence
     */
    private function blame(Version $older, Version $newer, Collection $own): ?int
    {
        return $this->hashIsValid($newer) && $own->has($older->sequence) ? $older->version : null;
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

    /**
     * @param  Collection<int, Version>  $versions
     * @param  array<int, true>  $invalid
     */
    private function lastValidVersion(Collection $versions, array $invalid): ?int
    {
        $lastValid = null;
        $expected = 1;

        foreach ($versions as $version) {
            if ($version->version !== $expected || isset($invalid[$version->version])) {
                break;
            }

            $lastValid = $version->version;
            $expected++;
        }

        return $lastValid;
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
     * @param  array{string|null, string|null}  $subject
     */
    protected function gapError(array $subject, int $missingSequence): IntegrityError
    {
        return $this->error(IntegrityErrorType::SequenceGap, "Sequence {$missingSequence} is missing.", $subject, null, $missingSequence);
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
     * @return array{sequence: int, hash: string|null}
     */
    protected function head(): array
    {
        $head = DB::connection($this->connection())
            ->table(Config::string('model-integrity.tables.heads'))
            ->where('chain', 'global')
            ->first();

        if ($head === null) {
            throw IntegrityConfigurationException::headMissing('global');
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

    protected function keyOnlyInstance(Model $prototype, int|string $id): Model
    {
        return $prototype->newInstance()->forceFill([$prototype->getKeyName() => $id]);
    }

    /**
     * @return Builder<Version>
     */
    protected function versionsOf(Model $model): Builder
    {
        return Version::query()
            ->where('versionable_type', $model->getMorphClass())
            ->where('versionable_id', $this->key($model))
            ->orderBy('version');
    }

    protected function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '';
    }
}
