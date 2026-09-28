<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;

/**
 * A recorded, append-only version of a model.
 *
 * @property int $id
 * @property int $sequence
 * @property string $versionable_type
 * @property string $versionable_id
 * @property int $version
 * @property string $event
 * @property int $hash_format
 * @property int $schema_version
 * @property array<string, mixed> $snapshot
 * @property string|null $prev_hash
 * @property string|null $global_prev_hash
 * @property string $hash
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed>|null $context
 * @property-read CarbonImmutable|null $created_at
 */
class Version extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableModelException::versionModification());
        static::deleting(fn () => throw ImmutableModelException::versionModification());
    }

    public function getTable(): string
    {
        return Config::string('model-integrity.tables.versions', 'integrity_versions');
    }

    public function getConnectionName(): ?string
    {
        $connection = config('model-integrity.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * The envelope the hash of this version was computed from.
     *
     * @return array<string, mixed>
     */
    public function toEnvelope(): array
    {
        return [
            'format' => $this->hash_format,
            'sequence' => $this->sequence,
            'versionable_type' => $this->versionable_type,
            'versionable_id' => $this->versionable_id,
            'version' => $this->version,
            'event' => $this->event,
            'schema_version' => $this->schema_version,
            'snapshot' => $this->snapshot,
            'prev_hash' => $this->prev_hash,
            'global_prev_hash' => $this->global_prev_hash,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'reason' => $this->reason,
            'context' => $this->context,
            'created_at' => app(CanonicalSerializer::class)->normalize($this->created_at),
        ];
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'versionable_id' => 'string',
            'version' => 'integer',
            'hash_format' => 'integer',
            'schema_version' => 'integer',
            'snapshot' => 'array',
            'actor_id' => 'string',
            'context' => 'array',
        ];
    }

    /**
     * Stored in UTC; parsed explicitly so the app timezone cannot shift it.
     *
     * @return Attribute<CarbonImmutable|null, never>
     */
    protected function createdAt(): Attribute
    {
        return Attribute::get(fn (mixed $value): ?CarbonImmutable => is_string($value)
            ? new CarbonImmutable($value, 'UTC')
            : null);
    }
}
