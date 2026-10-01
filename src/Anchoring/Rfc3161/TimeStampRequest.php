<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161;

use InvalidArgumentException;

/**
 * A TimeStampReq (RFC 3161, section 2.4.1) for a SHA-256 digest, asking for
 * the TSA certificate in the response.
 */
final readonly class TimeStampRequest
{
    public const string SHA256 = '2.16.840.1.101.3.4.2.1';

    public function __construct(
        /** 32 bytes */
        public string $digest,
        /** big-endian bytes of a non-negative nonce */
        public string $nonce,
        /** the TSA policy to ask for, as dotted OID */
        public ?string $policy = null,
    ) {
        if (strlen($digest) !== 32) {
            throw new InvalidArgumentException('A time-stamp request needs a 32-byte SHA-256 digest.');
        }
    }

    public static function for(string $digest, ?string $policy = null): self
    {
        // 64 random bits, high bit cleared so the INTEGER needs no padding byte.
        $nonce = random_bytes(8);
        $nonce[0] = chr(ord($nonce[0]) & 0x7F);

        return new self($digest, $nonce, $policy);
    }

    public function encode(): string
    {
        return Der::sequence(
            Der::integer(1),
            Der::sequence(Der::sequence(Der::oid(self::SHA256), Der::null()), Der::octetString($this->digest)),
            $this->policy === null ? '' : Der::oid($this->policy),
            Der::unsignedInteger($this->nonce),
            Der::boolean(true),
        );
    }
}
