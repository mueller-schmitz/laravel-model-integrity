<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Exceptions\UnsupportedHashFormatException;

/*
 * Reference vectors pin anchor format 1. The canonical strings were written by
 * hand and the digests computed with Python's hashlib, independently of this
 * package. Never update them to make a test pass; introduce a new format.
 */

it('produces the reference statements and digests', function (AnchorStatement $statement, string $canonical, string $digest): void {
    expect($statement->canonical())->toBe($canonical)
        ->and($statement->digest())->toBe($digest);
})->with([
    'first anchor' => [
        fn () => new AnchorStatement(1, 1, 3, '0073e5dfb5d3c6f71fb0dc1db2f096e02a2d6fd6d7a59d23c100b15a8488dac4', null),
        '{"anchor_format":1,"from_sequence":1,"merkle_root":"0073e5dfb5d3c6f71fb0dc1db2f096e02a2d6fd6d7a59d23c100b15a8488dac4","prev_digest":null,"to_sequence":3}',
        '9f56df2d9e35752e3e32f123d8740305a8efab639b59d7260f05c3497f70a74c',
    ],
    'chained anchor' => [
        fn () => new AnchorStatement(1, 4, 8, '4e7de5affaa10733332923d9eb1b8557bc889c448f0f31aeffc9dcac42135a2c', '9f56df2d9e35752e3e32f123d8740305a8efab639b59d7260f05c3497f70a74c'),
        '{"anchor_format":1,"from_sequence":4,"merkle_root":"4e7de5affaa10733332923d9eb1b8557bc889c448f0f31aeffc9dcac42135a2c","prev_digest":"9f56df2d9e35752e3e32f123d8740305a8efab639b59d7260f05c3497f70a74c","to_sequence":8}',
        'c5aa053bd2faaa6050b70bf8f83df064aff38a05f3f4ecc1bb1e700e6ea38879',
    ],
]);

it('rejects an unknown anchor format', function (): void {
    new AnchorStatement(2, 1, 3, str_repeat('a', 64), null);
})->throws(UnsupportedHashFormatException::class);

it('rejects invalid statements', function (int $from, int $to, string $root, ?string $prev): void {
    new AnchorStatement(1, $from, $to, $root, $prev);
})->with([
    'from below 1' => [0, 3, str_repeat('a', 64), null],
    'to before from' => [5, 4, str_repeat('a', 64), null],
    'invalid root' => [1, 3, 'xyz', null],
    'invalid previous digest' => [1, 3, str_repeat('a', 64), 'XYZ'],
])->throws(InvalidArgumentException::class);
