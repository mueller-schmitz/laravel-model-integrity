<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Hashing;

use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidEnvelopeException;
use MuellerSchmitz\ModelIntegrity\Exceptions\UnsupportedHashFormatException;

/**
 * Computes version hashes as sha256 over the canonical JSON of an envelope.
 *
 * The field list of a released format must never change. New fields or rules
 * require a new format number.
 */
class Hasher
{
    /**
     * @var array<int, list<string>>
     */
    private const array FORMATS = [
        1 => [
            'actor_id',
            'actor_type',
            'context',
            'created_at',
            'event',
            'format',
            'global_prev_hash',
            'prev_hash',
            'reason',
            'schema_version',
            'sequence',
            'snapshot',
            'version',
            'versionable_id',
            'versionable_type',
        ],
    ];

    public function __construct(
        private readonly CanonicalSerializer $serializer,
    ) {}

    /**
     * @return list<string>
     */
    public static function fields(int $format): array
    {
        return self::FORMATS[$format] ?? throw UnsupportedHashFormatException::for($format);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function hash(array $envelope): string
    {
        return hash('sha256', $this->canonical($envelope));
    }

    /**
     * The exact string that is hashed, e.g. for auditors recomputing hashes.
     *
     * @param  array<string, mixed>  $envelope
     */
    public function canonical(array $envelope): string
    {
        $format = $envelope['format'] ?? null;

        if (! is_int($format)) {
            throw UnsupportedHashFormatException::for($format);
        }

        $expected = self::fields($format);
        $given = array_keys($envelope);

        $missing = array_values(array_diff($expected, $given));
        $unknown = array_values(array_diff($given, $expected));

        if ($missing !== [] || $unknown !== []) {
            throw InvalidEnvelopeException::fieldMismatch($format, $missing, $unknown);
        }

        return $this->serializer->encode($envelope);
    }
}
