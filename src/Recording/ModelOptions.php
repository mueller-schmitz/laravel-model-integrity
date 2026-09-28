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
     */
    private function __construct(
        public array $except,
        public array $relations,
    ) {}

    public static function of(Model $model): self
    {
        if (! method_exists($model, 'getIntegrityExcept') || ! method_exists($model, 'getIntegrityRelations')) {
            throw IntegrityConfigurationException::notTracked($model::class);
        }

        return new self(
            self::strings($model->getIntegrityExcept()),
            self::strings($model->getIntegrityRelations()),
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
