<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use InvalidArgumentException;

/**
 * Merkle tree hash as specified in RFC 6962 (section 2.1): a leaf is
 * sha256(0x00 || hash), a node sha256(0x01 || left || right), and a tree of n
 * leaves is split at the largest power of two below n. Leaves and nodes are
 * domain-separated and no leaf is duplicated, so neither a node nor a padded
 * list can pass for another tree.
 *
 * The root is computed while streaming: the stack holds at most one perfect
 * subtree per bit of the leaf count.
 */
class MerkleTree
{
    /**
     * @param  iterable<string>  $hashes  SHA-256 hashes in lowercase hex, in order
     * @return string|null the root in lowercase hex, null without leaves
     */
    public function root(iterable $hashes): ?string
    {
        /** @var list<array{int, string}> $stack perfect subtrees as [leaf count, hash], largest first */
        $stack = [];

        foreach ($hashes as $hash) {
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new InvalidArgumentException('Merkle leaves must be SHA-256 hashes in lowercase hex.');
            }

            $subtree = [1, hash('sha256', "\x00".hex2bin($hash), true)];

            // Two subtrees of equal size form the next larger perfect subtree.
            while ($stack !== [] && $stack[array_key_last($stack)][0] === $subtree[0]) {
                [$size, $left] = array_pop($stack);
                $subtree = [$size * 2, $this->node($left, $subtree[1])];
            }

            $stack[] = $subtree;
        }

        if ($stack === []) {
            return null;
        }

        // The remaining subtrees shrink from left to right; joined from the
        // right, they give the split at the largest power of two.
        [, $root] = array_pop($stack);

        while ($stack !== []) {
            [, $left] = array_pop($stack);
            $root = $this->node($left, $root);
        }

        return bin2hex($root);
    }

    private function node(string $left, string $right): string
    {
        return hash('sha256', "\x01".$left.$right, true);
    }
}
