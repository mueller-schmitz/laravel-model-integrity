<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161;

use Carbon\CarbonImmutable;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

/**
 * The signed content of a time-stamp token (RFC 3161, section 2.4.2): what
 * was time-stamped, when, and under which policy.
 */
final readonly class TstInfo
{
    public function __construct(
        public string $policy,
        public string $hashAlgorithm,
        public string $hashedMessage,
        public string $serialNumber,
        public CarbonImmutable $genTime,
        public ?string $nonce,
    ) {}

    public static function decode(string $der): self
    {
        $children = Der::decode($der)->expect(Der::SEQUENCE)->children();

        if (count($children) < 5 || $children[0]->integer() !== 1) {
            throw new InvalidTimestampException('Unsupported TSTInfo version.');
        }

        $imprint = $children[2]->expect(Der::SEQUENCE)->children();

        if (count($imprint) !== 2) {
            throw new InvalidTimestampException('Malformed message imprint.');
        }

        $algorithm = $imprint[0]->expect(Der::SEQUENCE)->children();
        $nonce = null;

        // accuracy, ordering, nonce, tsa and extensions are optional, in this order.
        foreach (array_slice($children, 5) as $optional) {
            if ($optional->tag === Der::INTEGER) {
                $nonce = $optional->unsignedBytes();
            }
        }

        return new self(
            $children[1]->oid(),
            $algorithm[0]->oid(),
            $imprint[1]->expect(Der::OCTET_STRING)->content,
            $children[3]->unsignedBytes(),
            $children[4]->generalizedTime(),
            $nonce,
        );
    }
}
