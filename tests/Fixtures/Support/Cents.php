<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A custom cast: euros in the model, cents in the database.
 *
 * @implements CastsAttributes<float, int>
 */
class Cents implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): float
    {
        return (int) $value / 100;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return (int) round((float) $value * 100);
    }
}
