<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidEnvelopeException;
use MuellerSchmitz\ModelIntegrity\Exceptions\UnsupportedHashFormatException;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;

function formatOneEnvelope(array $overrides = []): array
{
    return array_merge([
        'format' => 1,
        'sequence' => 1,
        'versionable_type' => 'App\Models\Invoice',
        'versionable_id' => '42',
        'version' => 1,
        'event' => 'created',
        'schema_version' => 1,
        'snapshot' => ['id' => 42, 'total' => '100.00'],
        'prev_hash' => null,
        'global_prev_hash' => null,
        'actor_type' => null,
        'actor_id' => null,
        'reason' => null,
        'context' => null,
        'created_at' => '2026-09-28T10:00:00.000000Z',
    ], $overrides);
}

beforeEach(function (): void {
    $this->hasher = new Hasher(new CanonicalSerializer);
});

it('returns a lowercase sha256 hex digest of the canonical envelope', function (): void {
    $hash = $this->hasher->hash(formatOneEnvelope());

    expect($hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($hash)->toBe(hash('sha256', $this->hasher->canonical(formatOneEnvelope())));
});

it('is independent of the key order', function (): void {
    expect($this->hasher->hash(array_reverse(formatOneEnvelope(), true)))->toBe($this->hasher->hash(formatOneEnvelope()));
});

it('changes the hash when any field changes', function (string $field, mixed $value): void {
    expect($this->hasher->hash(formatOneEnvelope([$field => $value])))->not->toBe($this->hasher->hash(formatOneEnvelope()));
})->with([
    ['sequence', 2],
    ['versionable_type', 'App\Models\Order'],
    ['versionable_id', '43'],
    ['version', 2],
    ['event', 'updated'],
    ['schema_version', 2],
    ['snapshot', ['id' => 42, 'total' => '100.01']],
    ['prev_hash', str_repeat('a', 64)],
    ['global_prev_hash', str_repeat('b', 64)],
    ['actor_type', 'App\Models\User'],
    ['actor_id', '1'],
    ['reason', 'Correction'],
    ['context', ['ip' => '127.0.0.1']],
    ['created_at', '2026-09-28T10:00:00.000001Z'],
]);

it('distinguishes an integer id from a string id', function (): void {
    expect($this->hasher->hash(formatOneEnvelope(['versionable_id' => 42])))
        ->not->toBe($this->hasher->hash(formatOneEnvelope(['versionable_id' => '42'])));
});

it('rejects an envelope with a missing field', function (): void {
    $envelope = formatOneEnvelope();
    unset($envelope['global_prev_hash']);

    $this->hasher->hash($envelope);
})->throws(InvalidEnvelopeException::class, 'global_prev_hash');

it('rejects an envelope with an unknown field', function (): void {
    $this->hasher->hash(formatOneEnvelope(['id' => 7]));
})->throws(InvalidEnvelopeException::class, 'id');

it('rejects an envelope without a format', function (): void {
    $envelope = formatOneEnvelope();
    unset($envelope['format']);

    $this->hasher->hash($envelope);
})->throws(UnsupportedHashFormatException::class);

it('rejects an unknown format', function (mixed $format): void {
    $this->hasher->hash(formatOneEnvelope(['format' => $format]));
})->with([0, 2, '1'])->throws(UnsupportedHashFormatException::class);

it('lists the fields of format 1', function (): void {
    expect(Hasher::fields(1))->toBe([
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
    ]);
});
