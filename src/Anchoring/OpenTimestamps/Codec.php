<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

/**
 * Reads and writes the binary OpenTimestamps format.
 *
 * A node is written as its entries – attestations first, then operations,
 * each group in byte order of its encoding – with 0xff before every entry but
 * the last. An attestation is 0x00, its 8-byte tag and its payload as
 * varbytes; an operation is its tag, its argument as varbytes for append and
 * prepend, and the node it leads to.
 */
final class Codec
{
    public const string MAGIC = "\x00OpenTimestamps\x00\x00Proof\x00\xbf\x89\xe2\xe8\x84\xe8\x92\x94";

    public const int MAJOR_VERSION = 1;

    /** Deepest nesting of operations read; proofs of public calendars need far less. */
    public const int MAX_DEPTH = 256;

    /** Real proofs, even upgraded ones from several calendars, have a few kilobytes. */
    public const int MAX_SIZE = 65_536;

    /** Limits what a proof can make the reader hold: nodes, and message bytes in all nodes. */
    public const int MAX_NODES = 10_000;

    public const int MAX_MESSAGE_BYTES = 2_097_152;

    private int $nodes = 0;

    private int $messageBytes = 0;

    private const int FORK = 0xFF;

    private const int ATTESTATION = 0x00;

    public function decodeTimestamp(string $bytes, string $message): Timestamp
    {
        $this->assertSize($bytes);
        $reader = new ByteReader($bytes);
        $timestamp = new Timestamp($message);
        $this->readInto($reader, $timestamp, 0);
        $reader->assertEnd();

        return $timestamp;
    }

    public function encodeTimestamp(Timestamp $timestamp): string
    {
        $entries = [
            ...array_map(fn (Attestation $attestation): string => chr(self::ATTESTATION).$attestation->tag.Bytes::varbytes($attestation->payload), $timestamp->attestations()),
            ...array_map(fn (array $entry): string => $this->encodeOp($entry[0]).$this->encodeTimestamp($entry[1]), $timestamp->ops()),
        ];

        if ($entries === []) {
            throw new InvalidTimestampException('A timestamp node needs an attestation or an operation.');
        }

        return implode('', array_map(fn (string $entry): string => chr(self::FORK).$entry, array_slice($entries, 0, -1))).end($entries);
    }

    public function decodeDetached(string $bytes): DetachedTimestamp
    {
        $this->assertSize($bytes);
        $reader = new ByteReader($bytes);

        if ($reader->bytes(strlen(self::MAGIC)) !== self::MAGIC) {
            throw new InvalidTimestampException('Not an OpenTimestamps proof file.');
        }

        if (($version = $reader->varuint()) !== self::MAJOR_VERSION) {
            throw new InvalidTimestampException("Unsupported OpenTimestamps proof version [{$version}].");
        }

        if ($reader->byte() !== Op::SHA256) {
            throw new InvalidTimestampException('Only proofs of SHA-256 file digests are supported.');
        }

        $digest = $reader->bytes(32);
        $timestamp = new Timestamp($digest);
        $this->readInto($reader, $timestamp, 0);
        $reader->assertEnd();

        return new DetachedTimestamp($digest, $timestamp);
    }

    public function encodeDetached(DetachedTimestamp $file): string
    {
        return self::MAGIC.Bytes::varuint(self::MAJOR_VERSION).chr(Op::SHA256).$file->digest.$this->encodeTimestamp($file->timestamp);
    }

    /**
     * Reads the entries of a node into it. Nodes are read in place, so every
     * node is created once, whatever the depth.
     */
    private function readInto(ByteReader $reader, Timestamp $timestamp, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidTimestampException('The timestamp is nested too deep.');
        }

        if ($depth === 0) {
            $this->nodes = 0;
            $this->messageBytes = 0;
        }

        if (++$this->nodes > self::MAX_NODES || ($this->messageBytes += strlen($timestamp->message)) > self::MAX_MESSAGE_BYTES) {
            throw new InvalidTimestampException('The timestamp has too many nodes or message bytes.');
        }

        $tag = $reader->byte();

        while ($tag === self::FORK) {
            $this->readEntry($reader, $timestamp, $reader->byte(), $depth);
            $tag = $reader->byte();
        }

        $this->readEntry($reader, $timestamp, $tag, $depth);
    }

    private function readEntry(ByteReader $reader, Timestamp $timestamp, int $tag, int $depth): void
    {
        if ($tag === self::ATTESTATION) {
            $attestationTag = $reader->bytes(8);
            $timestamp->attest(new Attestation($attestationTag, $reader->varbytes(Attestation::MAX_PAYLOAD_LENGTH, 0)));

            return;
        }

        $op = Op::fromTag($tag, in_array($tag, [Op::APPEND, Op::PREPEND], true) ? $reader->varbytes(Op::MAX_MESSAGE_LENGTH) : null);

        $this->readInto($reader, $timestamp->add($op), $depth + 1);
    }

    private function encodeOp(Op $op): string
    {
        return chr($op->tag).($op->argument === null ? '' : Bytes::varbytes($op->argument));
    }

    private function assertSize(string $bytes): void
    {
        if (strlen($bytes) > self::MAX_SIZE) {
            throw new InvalidTimestampException('The timestamp is larger than '.self::MAX_SIZE.' bytes.');
        }
    }
}
