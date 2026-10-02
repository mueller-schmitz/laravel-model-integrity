<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Facades;

use Illuminate\Support\Facades\Facade;
use MuellerSchmitz\ModelIntegrity\Shredding\Subjects;

/**
 * @method static string|null shred(\Illuminate\Database\Eloquent\Model|string $subject, ?string $reason = null)
 * @method static bool isShredded(\Illuminate\Database\Eloquent\Model|string $subject)
 *
 * @see Subjects
 */
class IntegritySubjects extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Subjects::class;
    }
}
