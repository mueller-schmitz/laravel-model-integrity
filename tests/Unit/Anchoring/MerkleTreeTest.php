<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Anchoring\MerkleTree;

/**
 * @return list<string>
 */
function merkleLeaves(int $count): array
{
    return array_map(fn (int $i): string => hash('sha256', (string) $i), $count === 0 ? [] : range(1, $count));
}

/**
 * Straightforward recursive RFC 6962 tree hash, to compare the streaming one against.
 *
 * @param  list<string>  $leaves
 */
function naiveMerkleRoot(array $leaves): string
{
    $count = count($leaves);

    if ($count === 1) {
        return hash('sha256', "\x00".hex2bin($leaves[0]), true);
    }

    $split = 1;
    while ($split * 2 < $count) {
        $split *= 2;
    }

    return hash('sha256', "\x01".naiveMerkleRoot(array_slice($leaves, 0, $split)).naiveMerkleRoot(array_slice($leaves, $split)), true);
}

// Computed independently with Python (hashlib), RFC 6962 tree hash over
// leaves sha256("1"), sha256("2"), ... A changed value is always a bug.
it('matches the reference roots', function (int $count, string $root): void {
    expect((new MerkleTree)->root(merkleLeaves($count)))->toBe($root);
})->with([
    [1, '58705e7af8dbab9f2f5b6449ba18d22cce7eedf245fca8dcfd93cf0f906ccf95'],
    [2, '6e8393d7b8c8c1d492cbd897fa417689fe9a5b73cb6188a3b62af0bf8d4ddce6'],
    [3, '0073e5dfb5d3c6f71fb0dc1db2f096e02a2d6fd6d7a59d23c100b15a8488dac4'],
    [5, '4e7de5affaa10733332923d9eb1b8557bc889c448f0f31aeffc9dcac42135a2c'],
    [8, 'b43cfde65b63a9ae1546161d263843339285d210e35186a506613bd59f17b1a5'],
    [1000, 'c6267cfb368878b2529063aef359e484dc45ced7a40e3a91f230fe73a1a632c3'],
]);

it('matches the recursive tree hash for every size up to 70', function (): void {
    foreach (range(1, 70) as $count) {
        expect((new MerkleTree)->root(merkleLeaves($count)))->toBe(bin2hex(naiveMerkleRoot(merkleLeaves($count))), "{$count} leaves");
    }
});

it('streams leaves from a generator', function (): void {
    $generator = (function () {
        foreach (merkleLeaves(5) as $leaf) {
            yield $leaf;
        }
    })();

    expect((new MerkleTree)->root($generator))->toBe('4e7de5affaa10733332923d9eb1b8557bc889c448f0f31aeffc9dcac42135a2c');
});

it('has no root without leaves', function (): void {
    expect((new MerkleTree)->root([]))->toBeNull();
});

it('distinguishes a leaf from a node with the same bytes', function (): void {
    // Without domain separation, the root of two leaves could pass as a leaf.
    $two = merkleLeaves(2);

    expect((new MerkleTree)->root([(new MerkleTree)->root($two)]))->not->toBe((new MerkleTree)->root($two));
});

it('does not give an odd last leaf the weight of a duplicate', function (): void {
    $three = merkleLeaves(3);

    expect((new MerkleTree)->root($three))->not->toBe((new MerkleTree)->root([...$three, $three[2]]));
});

it('rejects leaves that are not SHA-256 hashes in lowercase hex', function (string $leaf): void {
    (new MerkleTree)->root([$leaf]);
})->with(['short' => ['abc'], 'uppercase' => [strtoupper(hash('sha256', 'x'))], 'binary' => [hash('sha256', 'x', true)]])
    ->throws(InvalidArgumentException::class);
