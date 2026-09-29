<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Facades;

use Illuminate\Support\Facades\Facade;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker as Checker;

/**
 * @method static \MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult checkModel(\Illuminate\Database\Eloquent\Model $model)
 * @method static \MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult checkType(class-string<\Illuminate\Database\Eloquent\Model> $class, bool $stopOnFirstFailure = false)
 * @method static \MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult checkChain()
 * @method static \MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult checkAll(bool $stopOnFirstFailure = false, ?\Closure $progress = null)
 * @method static \MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult checkFiles(bool $contents = true)
 * @method static \Illuminate\Support\Collection<int, \MuellerSchmitz\ModelIntegrity\Models\Version> getHistory(\Illuminate\Database\Eloquent\Model $model, bool $verify = false)
 * @method static \MuellerSchmitz\ModelIntegrity\Models\Version|null versionAt(\Illuminate\Database\Eloquent\Model $model, \DateTimeInterface|string $date)
 * @method static \Illuminate\Database\Eloquent\Model findModel(class-string<\Illuminate\Database\Eloquent\Model> $class, int|string $id)
 *
 * @see Checker
 */
class IntegrityChecker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Checker::class;
    }
}
