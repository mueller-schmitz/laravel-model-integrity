<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Shredding;

use Illuminate\Database\Eloquent\Model;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;

/**
 * Identifies a data subject: `<morph class>:<key>` of its model.
 */
final class SubjectName
{
    public static function of(Model $subject): string
    {
        $key = $subject->getKey();

        if (! is_scalar($key) || $key === '') {
            throw IntegrityConfigurationException::missingKey($subject);
        }

        return $subject->getMorphClass().':'.$key;
    }
}
