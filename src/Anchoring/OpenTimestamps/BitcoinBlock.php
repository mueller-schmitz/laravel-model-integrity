<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use Carbon\CarbonImmutable;

/**
 * The parts of a Bitcoin block header a Bitcoin attestation is checked against.
 */
final readonly class BitcoinBlock
{
    public function __construct(
        public int $height,
        /** 32 bytes in the byte order of the header, as Bitcoin attestations commit to it */
        public string $merkleRoot,
        public CarbonImmutable $time,
    ) {}
}
