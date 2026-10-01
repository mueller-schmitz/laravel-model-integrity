<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use InvalidArgumentException;

/**
 * Encodes the integer and byte string types of the OpenTimestamps format.
 */
final class Bytes
{
    /**
     * Unsigned LEB128: 7 bits per byte, least significant first, the high bit
     * marks that another byte follows.
     */
    public static function varuint(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('A varuint cannot be negative.');
        }

        $bytes = '';

        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $bytes .= chr($value > 0 ? $byte | 0x80 : $byte);
        } while ($value > 0);

        return $bytes;
    }

    /**
     * A byte string prefixed with its length as varuint.
     */
    public static function varbytes(string $bytes): string
    {
        return self::varuint(strlen($bytes)).$bytes;
    }
}
