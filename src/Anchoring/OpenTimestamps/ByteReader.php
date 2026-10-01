<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

/**
 * Reads the OpenTimestamps format from a byte string, failing on any read
 * past its end.
 */
final class ByteReader
{
    private int $position = 0;

    public function __construct(
        private readonly string $bytes,
    ) {}

    public function byte(): int
    {
        return ord($this->bytes(1));
    }

    public function bytes(int $length): string
    {
        if ($length < 0 || $this->position + $length > strlen($this->bytes)) {
            throw new InvalidTimestampException('The timestamp ends unexpectedly.');
        }

        $bytes = substr($this->bytes, $this->position, $length);
        $this->position += $length;

        return $bytes;
    }

    public function varuint(): int
    {
        $value = 0;

        for ($shift = 0; ; $shift += 7) {
            // Nine groups of 7 bits fill 63 bits, the most a PHP integer holds.
            if ($shift > 56) {
                throw new InvalidTimestampException('A varuint in the timestamp is too large.');
            }

            $byte = $this->byte();
            $value |= ($byte & 0x7F) << $shift;

            if (($byte & 0x80) === 0) {
                return $value;
            }
        }
    }

    public function varbytes(int $maxLength, int $minLength = 1): string
    {
        $length = $this->varuint();

        if ($length < $minLength || $length > $maxLength) {
            throw new InvalidTimestampException("A byte string in the timestamp must have {$minLength} to {$maxLength} bytes, {$length} given.");
        }

        return $this->bytes($length);
    }

    public function atEnd(): bool
    {
        return $this->position === strlen($this->bytes);
    }

    public function assertEnd(): void
    {
        if (! $this->atEnd()) {
            throw new InvalidTimestampException('The timestamp has trailing bytes.');
        }
    }
}
