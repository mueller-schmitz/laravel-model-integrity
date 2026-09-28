<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Recording;

use Illuminate\Database\Eloquent\Model;

/**
 * Names of the rows in the heads table: one for the global chain and one per
 * recorded model.
 */
final class ChainName
{
    public const string GLOBAL = 'global';

    /** Length of the `chain` column. */
    private const int MAX_LENGTH = 191;

    public static function forModel(Model $model): string
    {
        $key = $model->getKey();

        return self::for($model->getMorphClass(), is_scalar($key) ? (string) $key : '');
    }

    /**
     * `model:<type>:<id>`, or a hashed form when that exceeds the column.
     */
    public static function for(string $type, string $id): string
    {
        $name = "model:{$type}:{$id}";

        return strlen($name) <= self::MAX_LENGTH ? $name : 'model#'.hash('sha256', "{$type}|{$id}");
    }
}
