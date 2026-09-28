<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;

/*
 * Reference vectors pin hash format 1. The canonical strings were written by
 * hand and the hashes computed with sha256sum, independently of this package.
 *
 * A failing test here means released hashes would no longer verify.
 * Never update the fixture to make it pass; introduce a new format instead.
 */

dataset('hash format 1 vectors', function (): array {
    $vectors = json_decode(
        (string) file_get_contents(__DIR__.'/../../Fixtures/hash-format-1.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return collect($vectors)->mapWithKeys(fn (array $vector): array => [$vector['description'] => [$vector]])->all();
});

beforeEach(function (): void {
    $this->hasher = new Hasher(new CanonicalSerializer);
});

it('produces the reference canonical string', function (array $vector): void {
    expect($this->hasher->canonical($vector['envelope']))->toBe($vector['canonical']);
})->with('hash format 1 vectors');

it('produces the reference hash', function (array $vector): void {
    expect($this->hasher->hash($vector['envelope']))->toBe($vector['hash']);
})->with('hash format 1 vectors');

it('links the reference vectors into valid chains', function (): void {
    $vectors = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/hash-format-1.json'), true);
    [$first, $second, $third, $fourth] = array_column($vectors, 'envelope');
    [$h1, $h2, $h3] = array_column($vectors, 'hash');

    expect($second['prev_hash'])->toBe($h1)
        ->and($second['global_prev_hash'])->toBe($h1)
        ->and($third['global_prev_hash'])->toBe($h2)
        ->and($fourth['prev_hash'])->toBe($h2)
        ->and($fourth['global_prev_hash'])->toBe($h3)
        ->and($first['prev_hash'])->toBeNull()
        ->and($third['prev_hash'])->toBeNull();
});
