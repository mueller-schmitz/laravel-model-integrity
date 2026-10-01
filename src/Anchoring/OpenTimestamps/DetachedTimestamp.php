<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use InvalidArgumentException;

/**
 * The content of an .ots file: the SHA-256 digest of a file and the
 * timestamp proving when it existed.
 */
final readonly class DetachedTimestamp
{
    public function __construct(
        /** 32 bytes */
        public string $digest,
        public Timestamp $timestamp,
    ) {
        if (strlen($digest) !== 32 || $timestamp->message !== $digest) {
            throw new InvalidArgumentException('A detached timestamp needs a 32-byte digest that is the message of its timestamp.');
        }
    }
}
