<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Recording;

use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;

/**
 * Builds the canonical snapshot of a model from its stored database row.
 *
 * Reading the row (instead of the in-memory attributes) captures database
 * defaults and rounding, and makes the snapshot comparable with a later
 * re-read for the state drift check.
 */
class SnapshotBuilder
{
    /** Casts decoded from their JSON representation instead of Laravel's cast objects. */
    private const array JSON_CASTS = ['array', 'json', 'object', 'collection'];

    /** Cast classes storing JSON, including their parameterized forms (AsCollection::using(), AsEnumCollection::of()). */
    private const array JSON_CAST_CLASSES = [
        AsArrayObject::class,
        AsCollection::class,
        AsEnumArrayObject::class,
        AsEnumCollection::class,
    ];

    /**
     * Built-in casts normalized by Laravel's cast implementation. Checked before
     * class casts: class_exists('datetime') is true because of \DateTime.
     */
    private const array BUILTIN_CASTS = [
        'int', 'integer', 'real', 'float', 'double', 'decimal', 'string', 'bool', 'boolean',
        'date', 'datetime', 'custom_datetime', 'immutable_date', 'immutable_datetime',
        'immutable_custom_datetime', 'timestamp',
    ];

    public function __construct(
        private readonly CanonicalSerializer $serializer,
    ) {}

    /**
     * @param  list<string>  $except
     * @param  list<string>  $relations
     * @param  bool  $lock  read the row with a row lock, e.g. before deleting it
     * @param  list<string>  $omitNull  attributes left out while they are null
     * @return array<string, mixed>
     */
    public function build(Model $model, array $except = [], array $relations = [], bool $lock = false, array $omitNull = []): array
    {
        $row = $model->getConnection()
            ->table($model->getTable())
            ->where($model->getKeyName(), $model->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        if ($row === null) {
            throw IntegrityConfigurationException::rowMissing($model);
        }

        /** @var array<string, mixed> $attributes */
        $attributes = array_diff_key((array) $row, array_flip($except));

        foreach ($omitNull as $attribute) {
            if (($attributes[$attribute] ?? null) === null) {
                unset($attributes[$attribute]);
            }
        }

        $snapshot = $this->normalizeAttributes($model, $attributes);

        if ($relations !== []) {
            $snapshot['@relations'] = $this->relationKeys($model, $relations);
        }

        /** @var array<string, mixed> */
        return $this->serializer->normalize($snapshot);
    }

    /**
     * Normalizes raw database values by the casts of the model.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function normalizeAttributes(Model $model, array $attributes): array
    {
        $casts = $model->getCasts();
        $dates = $model->getDates();

        $normalized = [];

        foreach ($attributes as $key => $value) {
            $normalized[$key] = $value === null
                ? null
                : $this->normalizeValue($model, $key, $value, $casts[$key] ?? null, in_array($key, $dates, true));
        }

        return $normalized;
    }

    private function normalizeValue(Model $model, string $key, mixed $value, ?string $cast, bool $isDate): mixed
    {
        if ($cast === null) {
            return $isDate ? $this->callProtected($model, 'asDateTime', $value) : $value;
        }

        $castClass = explode(':', $cast, 2)[0];
        $type = strtolower($castClass);

        return match (true) {
            // Keep the stored ciphertext; decrypting would put plain text into the history.
            str_contains($type, 'encrypted') => $value,
            in_array($type, self::JSON_CASTS, true),
            in_array($castClass, self::JSON_CAST_CLASSES, true) => $this->decodeJson($value),
            // Plain dates carry no time: keep them as Y-m-d instead of shifting
            // midnight of the app timezone to UTC.
            in_array($type, ['date', 'immutable_date'], true) => $this->plainDate($model, $key, $value),
            in_array($type, self::BUILTIN_CASTS, true),
            enum_exists($cast) => $this->callProtected($model, 'castAttribute', $key, $value),
            // Custom cast classes and other casts (e.g. 'hashed'): keep the stored value.
            default => $value,
        };
    }

    private function plainDate(Model $model, string $key, mixed $value): string
    {
        $date = $this->callProtected($model, 'castAttribute', $key, $value);

        return $date instanceof DateTimeInterface ? $date->format('Y-m-d') : (is_scalar($value) ? (string) $value : '');
    }

    private function decodeJson(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;
    }

    /**
     * Uses Laravel's own cast implementation (bypassing accessors) so that
     * decimals, enums and dates behave exactly as they do on the model.
     */
    private function callProtected(Model $model, string $method, mixed ...$arguments): mixed
    {
        $call = Closure::bind(fn (mixed ...$args): mixed => $this->{$method}(...$args), $model, Model::class);

        return $call(...$arguments);
    }

    /**
     * @param  list<string>  $relations
     * @return array<string, list<string>>
     */
    private function relationKeys(Model $model, array $relations): array
    {
        $keys = [];

        foreach ($relations as $name) {
            if (! method_exists($model, $name) || ! ($relation = $model->{$name}()) instanceof Relation) {
                throw IntegrityConfigurationException::unknownRelation($model, $name);
            }

            $related = $relation->pluck($relation->getRelated()->getQualifiedKeyName())
                ->map(fn (mixed $key): string => is_scalar($key) ? (string) $key : '')
                ->all();

            sort($related, SORT_STRING);

            $keys[$name] = $related;
        }

        return $keys;
    }
}
