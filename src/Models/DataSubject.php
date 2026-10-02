<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;

/**
 * A data subject and the key of its personal data. Creating and shredding
 * the key are versions in the global chain; the key itself is never part
 * of a snapshot.
 *
 * @property string $id
 * @property string $subject
 * @property string|null $key wrapped with the application key, null once shredded
 * @property-read CarbonImmutable|null $created_at
 * @property string|null $shredded_at
 */
class DataSubject extends Model
{
    use HasIntegrity;
    use HasUlids;

    public const string MORPH_ALIAS = 'model-integrity.subject';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['key'];

    protected string $integrityMode = 'versioned';

    protected string $integrityDeletes = 'forbid';

    /**
     * The key is secret; created_at is set by the package in UTC and would
     * depend on the app timezone when read back.
     *
     * @var list<string>
     */
    protected array $integrityExcept = ['key', 'created_at'];

    public function getTable(): string
    {
        return Config::string('model-integrity.tables.subject_keys', 'integrity_subject_keys');
    }

    public function getConnectionName(): ?string
    {
        $connection = config('model-integrity.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    public function getMorphClass(): string
    {
        return self::MORPH_ALIAS;
    }

    /**
     * The key is not personal data of a subject: no subject of its own.
     */
    public function integritySubject(): ?Model
    {
        return null;
    }

    /**
     * @return Attribute<CarbonImmutable|null, never>
     */
    protected function createdAt(): Attribute
    {
        return Attribute::get(fn (mixed $value): ?CarbonImmutable => is_string($value) ? new CarbonImmutable($value, 'UTC') : null);
    }
}
