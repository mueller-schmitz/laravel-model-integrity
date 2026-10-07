<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Recording;

use Illuminate\Database\Eloquent\Model;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;

/**
 * The integrity settings of a model using HasIntegrity, read in a type-safe way.
 */
final readonly class ModelOptions
{
    /**
     * @param  list<string>  $except
     * @param  list<string>  $relations
     * @param  list<string>  $personal
     * @param  array<string, mixed>  $anonymized
     * @param  list<string>  $omitNull
     */
    private function __construct(
        public array $except,
        public array $relations,
        public int $schemaVersion,
        public array $personal = [],
        public array $anonymized = [],
        public array $omitNull = [],
    ) {}

    public static function of(Model $model): self
    {
        if (! method_exists($model, 'getIntegrityExcept')
            || ! method_exists($model, 'getIntegrityRelations')
            || ! method_exists($model, 'getIntegritySchemaVersion')) {
            throw IntegrityConfigurationException::notTracked($model::class);
        }

        $schemaVersion = $model->getIntegritySchemaVersion();
        $anonymized = method_exists($model, 'getIntegrityAnonymized') ? $model->getIntegrityAnonymized() : [];

        return new self(
            self::strings($model->getIntegrityExcept()),
            self::strings($model->getIntegrityRelations()),
            is_int($schemaVersion) ? $schemaVersion : 1,
            method_exists($model, 'getIntegrityPersonal') ? self::strings($model->getIntegrityPersonal()) : [],
            is_array($anonymized) ? array_filter($anonymized, is_string(...), ARRAY_FILTER_USE_KEY) : [],
            method_exists($model, 'getIntegrityOmitNull') ? self::strings($model->getIntegrityOmitNull()) : [],
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
