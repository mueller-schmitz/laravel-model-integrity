<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;

/**
 * An append-only anchor: a statement about a range of the global chain.
 *
 * @property int $id
 * @property int $anchor_format
 * @property int $from_sequence
 * @property int $to_sequence
 * @property string $merkle_root
 * @property string|null $prev_digest
 * @property string $digest
 * @property-read CarbonImmutable|null $created_at
 * @property-read Collection<int, AnchorProof> $proofs
 */
class AnchorRecord extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        // The anchorer inserts through the query builder, under the anchors head lock.
        static::creating(fn () => throw ImmutableModelException::anchorModification());
        static::updating(fn () => throw ImmutableModelException::anchorModification());
        static::deleting(fn () => throw ImmutableModelException::anchorModification());
    }

    public function getTable(): string
    {
        return Config::string('model-integrity.tables.anchors', 'integrity_anchors');
    }

    public function getConnectionName(): ?string
    {
        $connection = config('model-integrity.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * @return HasMany<AnchorProof, $this>
     */
    public function proofs(): HasMany
    {
        return $this->hasMany(AnchorProof::class, 'anchor_id')->orderBy('id');
    }

    /**
     * The statement as stored. Throws if the stored fields are no valid statement.
     */
    public function statement(): AnchorStatement
    {
        return new AnchorStatement($this->anchor_format, $this->from_sequence, $this->to_sequence, $this->merkle_root, $this->prev_digest);
    }

    protected function casts(): array
    {
        return [
            'anchor_format' => 'integer',
            'from_sequence' => 'integer',
            'to_sequence' => 'integer',
        ];
    }

    /**
     * Stored in UTC; parsed explicitly so the app timezone cannot shift it.
     *
     * @return Attribute<CarbonImmutable|null, never>
     */
    protected function createdAt(): Attribute
    {
        return Attribute::get(fn (mixed $value): ?CarbonImmutable => is_string($value) ? new CarbonImmutable($value, 'UTC') : null);
    }
}
