<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Exceptions\UnsupportedHashFormatException;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;

/**
 * What an anchor attests: the Merkle root of the version hashes in a range of
 * the global sequence, linked to the previous anchor. Drivers anchor its
 * digest, sha256 over the canonical JSON of the statement.
 *
 * The fields of a released anchor format must never change. New fields or
 * rules require a new format number.
 */
final readonly class AnchorStatement
{
    public const int FORMAT = 1;

    public function __construct(
        public int $format,
        public int $fromSequence,
        public int $toSequence,
        public string $merkleRoot,
        public ?string $prevDigest,
    ) {
        if ($format !== self::FORMAT) {
            throw UnsupportedHashFormatException::anchor($format);
        }

        if ($fromSequence < 1 || $toSequence < $fromSequence) {
            throw new InvalidArgumentException("Invalid anchor range [{$fromSequence}, {$toSequence}].");
        }

        foreach (['Merkle root' => $merkleRoot, 'previous digest' => $prevDigest ?? str_repeat('0', 64)] as $name => $hash) {
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException("The {$name} of an anchor must be a SHA-256 hash in lowercase hex.");
            }
        }
    }

    /**
     * Parses a statement from its canonical JSON, as stored by an anchor.
     *
     * @throws InvalidArgumentException when the string is not a canonical statement
     */
    public static function fromCanonical(string $json): self
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! is_int($data['anchor_format'] ?? null) || ! is_int($data['from_sequence'] ?? null)
            || ! is_int($data['to_sequence'] ?? null) || ! is_string($data['merkle_root'] ?? null)
            || ! array_key_exists('prev_digest', $data) || ! (is_string($data['prev_digest']) || $data['prev_digest'] === null)) {
            throw new InvalidArgumentException('Not an anchor statement.');
        }

        $statement = new self($data['anchor_format'], $data['from_sequence'], $data['to_sequence'], $data['merkle_root'], $data['prev_digest']);

        // Extra fields or another encoding would change the digest.
        if ($statement->canonical() !== $json) {
            throw new InvalidArgumentException('The anchor statement is not in canonical form.');
        }

        return $statement;
    }

    /**
     * The exact string whose hash is anchored, e.g. for auditors.
     */
    public function canonical(): string
    {
        return (new CanonicalSerializer)->encode([
            'anchor_format' => $this->format,
            'from_sequence' => $this->fromSequence,
            'to_sequence' => $this->toSequence,
            'merkle_root' => $this->merkleRoot,
            'prev_digest' => $this->prevDigest,
        ]);
    }

    public function digest(): string
    {
        return hash('sha256', $this->canonical());
    }

    /**
     * The name of a file holding the canonical statement. Zero-padded, so
     * names sort in sequence order; its SHA-256 hash is the digest.
     */
    public function fileName(): string
    {
        return sprintf('%020d-%s.json', $this->toSequence, $this->digest());
    }
}
