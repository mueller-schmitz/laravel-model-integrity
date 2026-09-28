<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use RuntimeException;

class CanonicalizationException extends RuntimeException
{
    public static function unsupportedType(string $path, string $type): self
    {
        $hint = str_starts_with($type, 'resource')
            ? ' Binary columns (e.g. PostgreSQL bytea) are not supported by hash format 1; exclude them via $integrityExcept.'
            : '';

        return new self("Cannot canonicalize value of type [{$type}] at [{$path}].".$hint);
    }

    public static function nonFiniteFloat(string $path): self
    {
        return new self("Cannot canonicalize non-finite float at [{$path}].");
    }

    public static function invalidUtf8(string $path): self
    {
        return new self(
            "Cannot canonicalize invalid UTF-8 string at [{$path}]. ".
            'Binary attributes are not supported by hash format 1; exclude them via $integrityExcept.'
        );
    }
}
