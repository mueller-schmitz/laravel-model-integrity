<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\SnapshotBuilder;
use MuellerSchmitz\ModelIntegrity\Recording\VersionRecorder;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

/**
 * Records every change of the model as a hashed, chained version.
 *
 * Optional properties on the model (the trait must not declare them):
 * - string $integrityMode: 'versioned' | 'immutable'
 * - string $integrityDeletes: 'forbid' | 'record'
 * - list<string> $integrityExcept: attributes excluded from snapshots
 * - list<string> $integrityRelations: relations whose keys are part of every snapshot
 * - int $integritySchemaVersion: version of the snapshot schema
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
            if (self::integrity($model)->getIntegrityMode() === 'immutable') {
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
     * @param  array<string, mixed>  $options
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
     * @param  array<string, mixed>|null  $snapshot
     */
    protected function recordIntegrityVersion(string $event, ?array $snapshot = null): Version
    {
        $this->getIntegrityMode();

        return app(VersionRecorder::class)->record(
            $this,
            $event,
            // Built lazily, after the recorder holds the chain head lock.
            $snapshot ?? fn (): array => $this->buildIntegritySnapshot(),
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
        return property_exists($this, $name) ? $this->{$name} : $default;
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
