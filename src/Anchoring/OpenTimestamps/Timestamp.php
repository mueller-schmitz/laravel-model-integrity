<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use InvalidArgumentException;

/**
 * A node of an OpenTimestamps proof: a message with the attestations made for
 * it and the operations that lead to further nodes. Several calendars and
 * later upgrades are merged into one tree.
 */
final class Timestamp
{
    /** @var array<string, Attestation> by key */
    private array $attestations = [];

    /** @var array<string, array{Op, Timestamp}> by operation key */
    private array $ops = [];

    public function __construct(
        public readonly string $message,
    ) {}

    public function attest(Attestation $attestation): self
    {
        $this->attestations[$attestation->key()] ??= $attestation;

        return $this;
    }

    /**
     * The node the operation leads to, created if it does not exist yet.
     */
    public function add(Op $op): self
    {
        return ($this->ops[$op->key()] ??= [$op, new self($op->apply($this->message))])[1];
    }

    /**
     * Adds the attestations and operations of another tree for the same message.
     */
    public function merge(self $other): self
    {
        if ($other->message !== $this->message) {
            throw new InvalidArgumentException('Only timestamps of the same message can be merged.');
        }

        foreach ($other->attestations as $attestation) {
            $this->attest($attestation);
        }

        foreach ($other->ops as [$op, $next]) {
            $this->add($op)->merge($next);
        }

        return $this;
    }

    /**
     * Whether every attestation and operation of the other tree is in this one.
     */
    public function contains(self $other): bool
    {
        if ($other->message !== $this->message || array_diff_key($other->attestations, $this->attestations) !== []) {
            return false;
        }

        foreach ($other->ops as $key => [, $next]) {
            if (! isset($this->ops[$key]) || ! $this->ops[$key][1]->contains($next)) {
                return false;
            }
        }

        return true;
    }

    public function isEmpty(): bool
    {
        return $this->attestations === [] && $this->ops === [];
    }

    /**
     * @return list<Attestation> in encoding order
     */
    public function attestations(): array
    {
        $attestations = $this->attestations;
        ksort($attestations, SORT_STRING);

        return array_values($attestations);
    }

    /**
     * @return list<array{Op, Timestamp}> in encoding order
     */
    public function ops(): array
    {
        $ops = $this->ops;
        ksort($ops, SORT_STRING);

        return array_values($ops);
    }

    /**
     * The nodes of the tree with the given message, e.g. the commitment of a
     * pending attestation that an upgrade continues from.
     *
     * @return list<Timestamp>
     */
    public function nodesFor(string $message): array
    {
        $nodes = $this->message === $message ? [$this] : [];

        foreach ($this->ops as [, $next]) {
            array_push($nodes, ...$next->nodesFor($message));
        }

        return $nodes;
    }

    /**
     * Every attestation in the tree with the message it attests.
     *
     * @return list<array{Attestation, string}>
     */
    public function allAttestations(): array
    {
        $all = array_map(fn (Attestation $attestation): array => [$attestation, $this->message], $this->attestations());

        foreach ($this->ops() as [, $next]) {
            array_push($all, ...$next->allAttestations());
        }

        return $all;
    }
}
