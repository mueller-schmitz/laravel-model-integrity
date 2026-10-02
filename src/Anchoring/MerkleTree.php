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

    /**
     * The audit path of a leaf (RFC 6962, section 2.1.1): the sibling hashes
     * from the leaf up to the root, which prove the leaf's inclusion without
     * the other leaves. Holds all leaves of the tree in memory.
     *
     * @param  list<string>  $hashes  SHA-256 hashes in lowercase hex, in order
     * @return list<string> lowercase hex, from the leaf up
     */
    public function auditPath(array $hashes, int $index): array
    {
        if ($index < 0 || $index >= count($hashes)) {
            throw new InvalidArgumentException("Leaf {$index} is not part of a tree of ".count($hashes).' leaves.');
        }

        $leaves = array_map(fn (string $hash): string => hash('sha256', "\x00".$this->binary($hash), true), $hashes);

        return array_map(bin2hex(...), $this->path($index, $leaves));
    }

    /**
     * Checks an audit path (RFC 9162, section 2.1.3.2).
     *
     * @param  string  $hash  the leaf: a SHA-256 hash in lowercase hex, as passed to root()
     * @param  list<string>  $path  from auditPath()
     */
    public function verifyInclusion(string $hash, int $index, int $size, array $path, string $root): bool
    {
        if ($index < 0 || $index >= $size) {
            return false;
        }

        $fn = $index;
        $sn = $size - 1;
        $result = hash('sha256', "\x00".$this->binary($hash), true);

        foreach ($path as $sibling) {
            if ($sn === 0 || preg_match('/^[0-9a-f]{64}$/', $sibling) !== 1) {
                return false;
            }

            $sibling = (string) hex2bin($sibling);

            if (($fn & 1) === 1 || $fn === $sn) {
                $result = $this->node($sibling, $result);

                while (($fn & 1) === 0 && $fn !== 0) {
                    $fn >>= 1;
                    $sn >>= 1;
                }
            } else {
                $result = $this->node($result, $sibling);
            }

            $fn >>= 1;
            $sn >>= 1;
        }

        return $sn === 0 && hash_equals($root, bin2hex($result));
    }

    /**
     * @param  list<string>  $leaves  binary leaf hashes
     * @return list<string> binary sibling hashes
     */
    private function path(int $index, array $leaves): array
    {
        $count = count($leaves);

        if ($count === 1) {
            return [];
        }

        $split = 1;
        while ($split * 2 < $count) {
            $split *= 2;
        }

        return $index < $split
            ? [...$this->path($index, array_slice($leaves, 0, $split)), $this->subtree(array_slice($leaves, $split))]
            : [...$this->path($index - $split, array_slice($leaves, $split)), $this->subtree(array_slice($leaves, 0, $split))];
    }

    /**
     * @param  list<string>  $leaves  binary leaf hashes
     */
    private function subtree(array $leaves): string
    {
        $count = count($leaves);

        if ($count === 1) {
            return $leaves[0];
        }

        $split = 1;
        while ($split * 2 < $count) {
            $split *= 2;
        }

        return $this->node($this->subtree(array_slice($leaves, 0, $split)), $this->subtree(array_slice($leaves, $split)));
    }

    private function binary(string $hash): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new InvalidArgumentException('Merkle leaves must be SHA-256 hashes in lowercase hex.');
        }

        return (string) hex2bin($hash);
    }

    private function node(string $left, string $right): string
    {
        return hash('sha256', "\x01".$left.$right, true);
    }
}
