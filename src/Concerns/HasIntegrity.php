<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ShreddedSubjectException;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\SnapshotBuilder;
use MuellerSchmitz\ModelIntegrity\Recording\VersionRecorder;
use MuellerSchmitz\ModelIntegrity\Shredding\PersonalData;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectKey;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectKeys;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectName;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;
use ReflectionProperty;

/**
 * Records every change of the model as a hashed, chained version.
 *
 * Optional properties on the model (the trait must not declare them):
 * - string $integrityMode: 'versioned' | 'immutable'
 * - string $integrityDeletes: 'forbid' | 'record'
 * - list<string> $integrityExcept: attributes excluded from snapshots
 * - list<string> $integrityRelations: relations whose keys are part of every snapshot
 * - int $integritySchemaVersion: version of the snapshot schema
 * - list<string> $integrityPersonal: personal attributes, recorded encrypted with
 *   the key of the data subject (integritySubject(), by default the model itself)
 * - array<string, mixed> $integrityAnonymized: values personal attributes may
 *   hold after the subject's key was shredded (besides null)
 *
 * save() and delete() run in a transaction, so the model change and its
 * version are committed together or not at all.
 *
 * @mixin Model
 */
trait HasIntegrity
{
    private ?string $integrityReason = null;

    /** @var array<string, mixed>|null */
    private ?array $integrityContext = null;

    /** @var array<string, mixed>|null Snapshot taken before a hard delete removes the row. */
    private ?array $integritySnapshotBeforeDelete = null;

    public static function bootHasIntegrity(): void
    {
        static::created(fn (Model $model) => self::integrity($model)->recordIntegrityVersion('created'));

        static::updating(function (Model $model): void {
            $model = self::integrity($model);

            // touch() and $touches only change excluded attributes such as updated_at.
            $relevant = array_diff(array_keys($model->getDirty()), $model->getIntegrityExcept());

            if ($model->getIntegrityMode() === 'immutable' && $relevant !== []) {
                throw ImmutableModelException::updateForbidden($model);
            }
        });

        static::updated(function (Model $model): void {
            $model = self::integrity($model);

            if ($model->hasRelevantIntegrityChanges()) {
                $model->recordIntegrityVersion($model->wasIntegrityRestored() ? 'restored' : 'updated');
            }
        });

        static::deleting(function (Model $model): void {
            $model = self::integrity($model);

            if ($model->getIntegrityDeletes() === 'forbid') {
                throw ImmutableModelException::deleteForbidden($model);
            }

            if (! $model->isIntegritySoftDelete()) {
                // Locked: a concurrent update must not slip between this read and the DELETE.
                $model->integritySnapshotBeforeDelete = $model->buildIntegritySnapshot(lock: true);
            }
        });

        static::deleted(function (Model $model): void {
            $model = self::integrity($model);
            $forced = method_exists($model, 'isForceDeleting') && $model->isForceDeleting();

            $model->recordIntegrityVersion($forced ? 'force_deleted' : 'deleted', $model->integritySnapshotBeforeDelete);
            $model->integritySnapshotBeforeDelete = null;
        });
    }

    /**
     * @param  array{touch?: bool|null}  $options
     */
    public function save(array $options = []): bool
    {
        try {
            return $this->getConnection()->transaction(fn (): bool => parent::save($options));
        } finally {
            $this->forgetIntegrityMetadata();
        }
    }

    public function delete(): ?bool
    {
        try {
            return $this->getConnection()->transaction(fn (): ?bool => parent::delete());
        } finally {
            $this->forgetIntegrityMetadata();
        }
    }

    /**
     * Reason stored with the next recorded version.
     */
    public function withIntegrityReason(?string $reason): static
    {
        $this->integrityReason = $reason;

        return $this;
    }

    /**
     * Context stored with the next recorded version.
     *
     * @param  array<string, mixed>|null  $context
     */
    public function withIntegrityContext(?array $context): static
    {
        $this->integrityContext = $context;

        return $this;
    }

    /**
     * Records the current state after sync(), attach() or detach() on a
     * declared relation; pivot changes fire no model events. Wrap the relation
     * change and this call in one transaction.
     */
    public function recordRelation(string $relation): Version
    {
        if (! in_array($relation, $this->getIntegrityRelations(), true)) {
            throw IntegrityConfigurationException::undeclaredRelation($this, $relation);
        }

        if ($this->getIntegrityMode() === 'immutable') {
            throw ImmutableModelException::updateForbidden($this);
        }

        try {
            return $this->recordIntegrityVersion('relation_synced');
        } finally {
            $this->forgetIntegrityMetadata();
        }
    }

    /**
     * Records the current state as a new version without changing the model,
     * e.g. after a schema change, a changed cast or newly declared relations
     * made the last snapshot outdated. Allowed in immutable mode.
     *
     * Lifecycle events are recorded automatically and cannot be used here;
     * 'created' is only allowed for a model without any version (backfill).
     */
    public function recordIntegritySnapshot(string $event = 'snapshot', ?string $reason = null): Version
    {
        // Stored in a 32 character column and shown in reports.
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/', $event) !== 1) {
            throw new InvalidArgumentException("Event [{$event}] must be 1 to 32 lowercase letters, digits or underscores, starting with a letter.");
        }

        if (in_array($event, ['updated', 'deleted', 'restored', 'force_deleted', 'relation_synced'], true)
            || ($event === 'created' && $this->integrityVersions()->exists())) {
            throw new InvalidArgumentException("Event [{$event}] is recorded automatically and cannot be used for a snapshot.");
        }

        $previousReason = $this->integrityReason;
        $this->integrityReason = $reason ?? $previousReason;

        try {
            return $this->recordIntegrityVersion($event);
        } finally {
            $this->forgetIntegrityMetadata();
        }
    }

    /**
     * All recorded versions of this model, oldest first.
     *
     * @return Builder<Version>
     */
    public function integrityVersions(): Builder
    {
        $key = $this->getKey();

        // Compared as string: versionable_id is a string column and PostgreSQL
        // does not compare varchar with integer.
        return Version::query()
            ->where('versionable_type', $this->getMorphClass())
            ->where('versionable_id', is_scalar($key) ? (string) $key : '')
            ->orderBy('version');
    }

    /**
     * All versions, oldest first; with $verify each version's isValid() is set.
     *
     * @return Collection<int, Version>
     */
    public function history(bool $verify = false): Collection
    {
        return app(IntegrityChecker::class)->getHistory($this, $verify);
    }

    public function verifyIntegrity(): IntegrityResult
    {
        return app(IntegrityChecker::class)->checkModel($this);
    }

    /**
     * The version that was current at the given moment.
     */
    public function versionAt(DateTimeInterface|string $date): ?Version
    {
        return app(IntegrityChecker::class)->versionAt($this, $date);
    }

    public function getIntegrityMode(): string
    {
        $mode = $this->integrityProperty('integrityMode', Config::string('model-integrity.defaults.mode', 'versioned'));

        return in_array($mode, ['versioned', 'immutable'], true)
            ? $mode
            : throw IntegrityConfigurationException::invalidOption($this, 'integrityMode', $mode);
    }

    public function getIntegrityDeletes(): string
    {
        $deletes = $this->integrityProperty('integrityDeletes', Config::string('model-integrity.defaults.deletes', 'forbid'));

        return in_array($deletes, ['forbid', 'record'], true)
            ? $deletes
            : throw IntegrityConfigurationException::invalidOption($this, 'integrityDeletes', $deletes);
    }

    /**
     * @return list<string>
     */
    public function getIntegrityExcept(): array
    {
        /** @var list<string> $default */
        $default = Config::array('model-integrity.defaults.except', ['updated_at']);

        return $this->integrityProperty('integrityExcept', $default);
    }

    /**
     * @return list<string>
     */
    public function getIntegrityRelations(): array
    {
        return $this->integrityProperty('integrityRelations', []);
    }

    public function getIntegritySchemaVersion(): int
    {
        return $this->integrityProperty('integritySchemaVersion', 1);
    }

    /**
     * @return list<string>
     */
    public function getIntegrityPersonal(): array
    {
        return $this->integrityProperty('integrityPersonal', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getIntegrityAnonymized(): array
    {
        return $this->integrityProperty('integrityAnonymized', []);
    }

    /**
     * The data subject whose key encrypts this model's personal attributes,
     * e.g. the customer of an order. Defaults to the model itself.
     */
    public function integritySubject(): ?Model
    {
        return $this;
    }

    /**
     * The data subject and its key (null once shredded). Creating a key
     * records a version of its own, so this runs before the recorder locks
     * the chain head for this model.
     *
     * @return array{string|null, SubjectKey|null}
     */
    protected function resolveIntegritySubjectKey(): array
    {
        if ($this->getIntegrityPersonal() === []) {
            return [null, null];
        }

        $subject = SubjectName::of($this->currentIntegritySubject());

        try {
            return [$subject, app(SubjectKeys::class)->keyFor($subject)];
        } catch (ShreddedSubjectException) {
            // Only anonymized values can be recorded; PersonalData checks them.
            return [$subject, null];
        }
    }

    /**
     * integritySubject() after dropping loaded belongs-to relations whose
     * foreign key changed: Eloquent keeps them, so a reassigned order would
     * otherwise still name its former customer.
     */
    protected function currentIntegritySubject(): Model
    {
        foreach (array_keys($this->getRelations()) as $name) {
            if (! is_string($name) || ! method_exists($this, $name)) {
                continue;
            }

            $relation = $this->{$name}();

            if ($relation instanceof BelongsTo) {
                $foreignKey = $relation->getForeignKeyName();

                if ($this->isDirty($foreignKey) || $this->wasChanged($foreignKey)) {
                    $this->unsetRelation($name);
                }
            }
        }

        return $this->integritySubject() ?? $this;
    }

    /**
     * Encrypts the personal attributes of a snapshot before it is recorded.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    protected function protectIntegritySnapshot(array $snapshot, ?string $subject, ?SubjectKey $key): array
    {
        $personal = $this->getIntegrityPersonal();

        if ($personal === [] || $subject === null) {
            return $snapshot;
        }

        $missing = array_values(array_diff($personal, array_keys($snapshot)));

        if ($missing !== []) {
            throw IntegrityConfigurationException::unknownPersonalAttributes($this, $missing);
        }

        return app(PersonalData::class)->encryptWith($snapshot, $personal, $key, $subject, $this->getIntegrityAnonymized());
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    protected function recordIntegrityVersion(string $event, ?array $snapshot = null): Version
    {
        $this->getIntegrityMode();
        [$subject, $key] = $this->resolveIntegritySubjectKey();

        return app(VersionRecorder::class)->record(
            $this,
            $event,
            // Built lazily, after the recorder holds the chain head lock.
            fn (): array => $this->protectIntegritySnapshot($snapshot ?? $this->buildIntegritySnapshot(), $subject, $key),
            $this->getIntegritySchemaVersion(),
            $this->integrityReason,
            $this->integrityContext,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildIntegritySnapshot(bool $lock = false): array
    {
        return app(SnapshotBuilder::class)->build($this, $this->getIntegrityExcept(), $this->getIntegrityRelations(), $lock);
    }

    protected function hasRelevantIntegrityChanges(): bool
    {
        return array_diff(array_keys($this->getChanges()), $this->getIntegrityExcept()) !== [];
    }

    /**
     * Restores fire their own event only after the save transaction, so they
     * are detected here: deleted_at changed from a value to null.
     */
    protected function wasIntegrityRestored(): bool
    {
        $column = method_exists($this, 'getDeletedAtColumn') ? $this->getDeletedAtColumn() : null;

        if (! is_string($column)) {
            return false;
        }

        return $this->wasChanged($column)
            && $this->getOriginal($column) !== null
            && $this->getAttribute($column) === null;
    }

    protected function isIntegritySoftDelete(): bool
    {
        return method_exists($this, 'isForceDeleting') && ! $this->isForceDeleting();
    }

    private function forgetIntegrityMetadata(): void
    {
        $this->integrityReason = null;
        $this->integrityContext = null;
    }

    /**
     * @template T
     *
     * @param  T  $default
     * @return T
     */
    private function integrityProperty(string $name, mixed $default): mixed
    {
        if (! property_exists($this, $name)) {
            return $default;
        }

        if (! (new ReflectionProperty($this, $name))->isInitialized($this)) {
            throw IntegrityConfigurationException::uninitializedProperty($this, $name);
        }

        return $this->{$name};
    }

    /**
     * Narrows the model passed to event callbacks to a model using this trait.
     */
    private static function integrity(Model $model): self
    {
        /** @var self $model */
        return $model;
    }
}
