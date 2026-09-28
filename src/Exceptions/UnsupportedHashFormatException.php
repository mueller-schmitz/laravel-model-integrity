<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use InvalidArgumentException;

class UnsupportedHashFormatException extends InvalidArgumentException
{
    public static function for(mixed $format): self
    {
        // json_encode distinguishes 1 from "1" and fails only for values that are no valid format anyway.
        return new self('Unsupported hash format ['.(json_encode($format) ?: get_debug_type($format)).'].');
    }
}
