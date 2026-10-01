<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Config;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;

/**
 * What an anchor driver returned for an anchor. Upgraded proofs (e.g. a
 * completed OpenTimestamps proof) are added as new rows; the latest row of a
 * driver is the one that counts.
 *
 * @property int $id
 * @property int $anchor_id
 * @property string $driver
 * @property string $proof base64-encoded
 * @property-read CarbonImmutable|null $created_at
 * @property-read AnchorRecord|null $anchor
 */
class AnchorProof extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn () => throw ImmutableModelException::anchorModification());
        static::updating(fn () => throw ImmutableModelException::anchorModification());
        static::deleting(fn () => throw ImmutableModelException::anchorModification());
    }

    public function getTable(): string
    {
        return Config::string('model-integrity.tables.anchor_proofs', 'integrity_anchor_proofs');
    }

    public function getConnectionName(): ?string
    {
        $connection = config('model-integrity.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * @return BelongsTo<AnchorRecord, $this>
     */
    public function anchor(): BelongsTo
    {
        return $this->belongsTo(AnchorRecord::class, 'anchor_id');
    }

    /**
     * The proof as the driver returned it; null if the stored value is not valid base64.
     */
    public function contents(): ?string
    {
        $decoded = base64_decode($this->proof, true);

        return $decoded === false ? null : $decoded;
    }

    protected function casts(): array
    {
        return ['anchor_id' => 'integer'];
    }

    /**
     * @return Attribute<CarbonImmutable|null, never>
     */
    protected function createdAt(): Attribute
    {
        return Attribute::get(fn (mixed $value): ?CarbonImmutable => is_string($value) ? new CarbonImmutable($value, 'UTC') : null);
    }
}
