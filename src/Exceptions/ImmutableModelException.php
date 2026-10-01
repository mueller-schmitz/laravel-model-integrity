<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class ImmutableModelException extends RuntimeException
{
    public static function updateForbidden(Model $model): self
    {
        return new self(sprintf(
            'Model [%s] with key [%s] is immutable and cannot be changed.',
            $model::class,
            self::key($model),
        ));
    }

    public static function deleteForbidden(Model $model): self
    {
        return new self(sprintf(
            'Model [%s] with key [%s] cannot be deleted; its integrity deletes mode is "forbid".',
            $model::class,
            self::key($model),
        ));
    }

    public static function versionModification(): self
    {
        return new self('Recorded versions are append-only and cannot be changed or deleted.');
    }

    public static function anchorModification(): self
    {
        return new self('Anchors are append-only and written only by model-integrity:anchor.');
    }

    private static function key(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '?';
    }
}
