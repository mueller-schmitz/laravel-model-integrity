<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class IntegrityConfigurationException extends LogicException
{
    public static function unknownRelation(Model $model, string $relation): self
    {
        return new self(sprintf('Relation [%s] declared in $integrityRelations does not exist on [%s].', $relation, $model::class));
    }

    public static function undeclaredRelation(Model $model, string $relation): self
    {
        return new self(sprintf('Relation [%s] is not declared in $integrityRelations of [%s].', $relation, $model::class));
    }

    public static function rowMissing(Model $model): self
    {
        return new self(sprintf(
            'No stored row found for [%s] with key [%s]. The row may have been deleted concurrently; lock rows that may be deleted before updating them.',
            $model::class,
            json_encode($model->getKey()),
        ));
    }

    public static function connectionMismatch(Model $model, string $modelConnection, string $integrityConnection): self
    {
        return new self(sprintf(
            'Model [%s] uses connection [%s], but integrity tables use [%s]. Both must be the same so model and version are written in one transaction.',
            $model::class,
            $modelConnection,
            $integrityConnection,
        ));
    }

    public static function missingKey(Model $model): self
    {
        return new self(sprintf(
            'Model [%s] has no key. Save it before recording; models without a single scalar primary key (e.g. composite keys) are not supported.',
            $model::class,
        ));
    }

    public static function uninitializedProperty(Model $model, string $property): self
    {
        return new self(sprintf('Property [$%s] on [%s] is declared without a value; assign one or remove the declaration.', $property, $model::class));
    }

    public static function notTracked(string $class): self
    {
        return new self("Model [{$class}] does not use the HasIntegrity trait.");
    }

    public static function headMissing(string $chain): self
    {
        return new self("The [{$chain}] chain head is missing. Run the model-integrity migrations.");
    }

    public static function invalidOption(Model $model, string $option, string $value): self
    {
        return new self(sprintf('Invalid value [%s] for [%s] on [%s].', $value, $option, $model::class));
    }
}
