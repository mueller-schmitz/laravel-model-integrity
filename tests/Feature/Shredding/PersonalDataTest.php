<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ShreddedSubjectException;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Shredding\PersonalData;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectKeys;

beforeEach(function (): void {
    $this->personal = app(PersonalData::class);
    $this->snapshot = ['id' => 7, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'phone' => null, 'total' => '9.99', 'tags' => ['a' => 1]];
});

it('encrypts personal attributes and leaves the others', function (): void {
    $encrypted = $this->personal->encrypt($this->snapshot, ['name', 'email', 'phone', 'tags'], 'customer:7');

    expect($encrypted['id'])->toBe(7)
        ->and($encrypted['total'])->toBe('9.99')
        ->and($encrypted['phone'])->toBeNull()
        ->and($encrypted['name'])->toHaveKey('@encrypted')
        ->and(json_encode($encrypted))->not->toContain('Ada Lovelace')->not->toContain('ada@example.com')
        ->and($encrypted['name']['@encrypted']['k'])->toBe($encrypted['email']['@encrypted']['k']);
});

it('reveals what it encrypted, also nested values', function (): void {
    $encrypted = $this->personal->encrypt($this->snapshot, ['name', 'email', 'tags'], 'customer:7');

    $revealed = $this->personal->reveal($encrypted);

    expect($revealed->snapshot)->toBe($this->snapshot)
        ->and($revealed->shredded)->toBe([]);
});

it('produces another ciphertext for the same value, which the canonical form keeps stable', function (): void {
    $first = $this->personal->encrypt($this->snapshot, ['name'], 'customer:7');
    $second = $this->personal->encrypt($this->snapshot, ['name'], 'customer:7');
    $serializer = new CanonicalSerializer;

    expect($first['name'])->not->toBe($second['name'])
        ->and($serializer->encode($serializer->normalize(json_decode($serializer->encode($first), true))))->toBe($serializer->encode($first));
});

it('reveals shredded attributes as null and names them', function (): void {
    $encrypted = $this->personal->encrypt($this->snapshot, ['name', 'email'], 'customer:7');
    app(SubjectKeys::class)->shred('customer:7');

    $revealed = $this->personal->reveal($encrypted);

    expect($revealed->snapshot['name'])->toBeNull()
        ->and($revealed->snapshot['total'])->toBe('9.99')
        ->and($revealed->shredded)->toBe(['email', 'name']);
});

it('records only null or the anonymized value for a shredded subject', function (): void {
    app(SubjectKeys::class)->shred('customer:7');

    $anonymized = $this->personal->encrypt(['name' => 'deleted', 'email' => null], ['name', 'email'], 'customer:7', ['name' => 'deleted']);

    expect($anonymized)->toBe(['name' => 'deleted', 'email' => null])
        ->and(fn () => $this->personal->encrypt($this->snapshot, ['name', 'email'], 'customer:7', ['name' => 'deleted']))
        ->toThrow(ShreddedSubjectException::class, 'email');
});

it('fails loudly when a value cannot be decrypted with its key', function (): void {
    $encrypted = $this->personal->encrypt($this->snapshot, ['name'], 'customer:7');
    $encrypted['name']['@encrypted']['c'] = $this->personal->encrypt($this->snapshot, ['name'], 'customer:8')['name']['@encrypted']['c'];

    $this->personal->reveal($encrypted);
})->throws(DecryptException::class);

it('rejects personal attributes that are not in the snapshot', function (): void {
    $this->personal->encrypt($this->snapshot, ['unknown'], 'customer:7');
})->throws(InvalidArgumentException::class, 'unknown');
