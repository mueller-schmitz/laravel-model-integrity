<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use InvalidArgumentException;

/**
 * All levels of an RFC 6962 Merkle tree, built once, so audit paths cost
 * O(log n) each. Pairing the nodes of each level and carrying a last odd
 * node up unchanged builds the same tree as the split at the largest power
 * of two below n.
 *
 * Holds two hashes per leaf (about 100 bytes per leaf in PHP).
 */
final readonly class MerkleLevels
{
    /**
     * @param  list<list<string>>  $levels  binary hashes, leaves first, the root last
     */
    private function __construct(
        private array $levels,
    ) {}

    /**
     * @param  list<string>  $leaves  binary leaf hashes (SHA-256 of 0x00 and the leaf)
     */
    public static function build(array $leaves): self
    {
        if ($leaves === []) {
            throw new InvalidArgumentException('A Merkle tree needs at least one leaf.');
        }

        $levels = [$leaves];

        while (count($level = $levels[count($levels) - 1]) > 1) {
            $next = [];

            for ($i = 0, $count = count($level); $i < $count; $i += 2) {
                $next[] = $i + 1 < $count ? hash('sha256', "\x01".$level[$i].$level[$i + 1], true) : $level[$i];
            }

            $levels[] = $next;
        }

        return new self($levels);
    }

    public function size(): int
    {
        return count($this->levels[0]);
    }

    public function root(): string
    {
        return bin2hex($this->levels[count($this->levels) - 1][0]);
    }

    /**
     * @return list<string> sibling hashes in lowercase hex, from the leaf up
     */
    public function path(int $index): array
    {
        if ($index < 0 || $index >= $this->size()) {
            throw new InvalidArgumentException("Leaf {$index} is not part of a tree of {$this->size()} leaves.");
        }

        $path = [];

        foreach (array_slice($this->levels, 0, -1) as $level) {
            $sibling = $index ^ 1;

            // A last odd node has no sibling on this level; it is carried up as it is.
            if ($sibling < count($level)) {
                $path[] = bin2hex($level[$sibling]);
            }

            $index >>= 1;
        }

        return $path;
    }
}
