<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

/**
 * A claim that the message of a node existed at some time: pending at a
 * calendar, or included in a Bitcoin block. Attestations of other kinds are
 * kept as read, so they survive re-encoding, but are not evaluated.
 */
final readonly class Attestation
{
    public const string PENDING = "\x83\xdf\xe3\x0d\x2e\xf9\x0c\x8e";

    public const string BITCOIN = "\x05\x88\x96\x0d\x73\xd7\x19\x01";

    public const int MAX_PAYLOAD_LENGTH = 8192;

    public const int MAX_URI_LENGTH = 1000;

    /**
     * @param  string  $tag  8 bytes
     * @param  string  $payload  the encoded payload of the attestation kind
     */
    public function __construct(
        public string $tag,
        public string $payload,
    ) {
        if (strlen($tag) !== 8) {
            throw new InvalidTimestampException('An attestation tag has 8 bytes.');
        }

        if (strlen($payload) > self::MAX_PAYLOAD_LENGTH) {
            throw new InvalidTimestampException('The payload of an attestation is too long.');
        }

        if ($tag === self::PENDING) {
            $this->uri();
        } elseif ($tag === self::BITCOIN) {
            $this->height();
        }
    }

    public static function pending(string $uri): self
    {
        return new self(self::PENDING, Bytes::varbytes($uri));
    }

    public static function bitcoin(int $height): self
    {
        return new self(self::BITCOIN, Bytes::varuint($height));
    }

    public function isPending(): bool
    {
        return $this->tag === self::PENDING;
    }

    public function isBitcoin(): bool
    {
        return $this->tag === self::BITCOIN;
    }

    /**
     * The calendar URI of a pending attestation.
     */
    public function uri(): ?string
    {
        if (! $this->isPending()) {
            return null;
        }

        $reader = new ByteReader($this->payload);
        $uri = $reader->varbytes(self::MAX_URI_LENGTH, 0);
        $reader->assertEnd();

        // Only characters a calendar URL needs; rules out control characters and other schemes' syntax.
        if (preg_match('#^[A-Za-z0-9\-_/:.]*$#', $uri) !== 1) {
            throw new InvalidTimestampException('The URI of a pending attestation contains invalid characters.');
        }

        return $uri;
    }

    /**
     * The block height of a Bitcoin attestation.
     */
    public function height(): ?int
    {
        if (! $this->isBitcoin()) {
            return null;
        }

        $reader = new ByteReader($this->payload);
        $height = $reader->varuint();
        $reader->assertEnd();

        return $height;
    }

    /**
     * Orders attestations when a node is written.
     */
    public function key(): string
    {
        return $this->tag.$this->payload;
    }
}
