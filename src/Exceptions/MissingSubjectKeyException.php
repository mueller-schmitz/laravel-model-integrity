<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use RuntimeException;

/**
 * Personal data refers to a subject key that is gone although it was never
 * shredded: the key row was removed or its key cleared outside the package.
 */
class MissingSubjectKeyException extends RuntimeException
{
    public static function for(string $keyId): self
    {
        return new self("The data subject key [{$keyId}] was removed without shredding.");
    }
}
