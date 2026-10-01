<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161;

use Carbon\CarbonImmutable;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

/**
 * One DER element: its identifier, its content and its complete encoding.
 */
final readonly class DerNode
{
    public function __construct(
        public int $tag,
        public string $content,
        /** the complete encoding: identifier, length and content */
        public string $encoded,
    ) {}

    public function isConstructed(): bool
    {
        return ($this->tag & 0x20) !== 0;
    }

    /**
     * The elements of a constructed element.
     *
     * @return list<DerNode>
     */
    public function children(): array
    {
        if (! $this->isConstructed()) {
            throw new InvalidTimestampException(sprintf('Element [0x%02x] has no children.', $this->tag));
        }

        return Der::decodeAll($this->content);
    }

    public function expect(int $tag): self
    {
        if ($this->tag !== $tag) {
            throw new InvalidTimestampException(sprintf('Expected element [0x%02x], found [0x%02x].', $tag, $this->tag));
        }

        return $this;
    }

    /**
     * A non-negative INTEGER that fits into a PHP integer.
     */
    public function integer(): int
    {
        $bytes = $this->expect(Der::INTEGER)->unsignedBytes();

        if (strlen($bytes) > 7) {
            throw new InvalidTimestampException('The integer is too large.');
        }

        return (int) hexdec(bin2hex($bytes));
    }

    /**
     * The magnitude of a non-negative INTEGER, without leading zero bytes.
     */
    public function unsignedBytes(): string
    {
        $content = $this->expect(Der::INTEGER)->content;

        if ($content === '' || (ord($content[0]) & 0x80) !== 0) {
            throw new InvalidTimestampException('Expected a non-negative integer.');
        }

        $trimmed = ltrim($content, "\x00");

        return $trimmed === '' ? "\x00" : $trimmed;
    }

    public function boolean(): bool
    {
        $content = $this->expect(Der::BOOLEAN)->content;

        if (strlen($content) !== 1) {
            throw new InvalidTimestampException('A boolean has one byte.');
        }

        return $content !== "\x00";
    }

    public function oid(): string
    {
        $content = $this->expect(Der::OID)->content;

        if ($content === '' || (ord($content[strlen($content) - 1]) & 0x80) !== 0) {
            throw new InvalidTimestampException('Malformed object identifier.');
        }

        $arcs = [];
        $value = 0;
        $startOfArc = true;

        foreach (str_split($content) as $byte) {
            // DER encodes each arc minimally: no leading 0x80 bytes.
            if ($startOfArc && ord($byte) === 0x80) {
                throw new InvalidTimestampException('Object identifier is not minimally encoded.');
            }

            $startOfArc = (ord($byte) & 0x80) === 0;

            if ($value > PHP_INT_MAX >> 7) {
                throw new InvalidTimestampException('Object identifier arc too large.');
            }

            $value = ($value << 7) | (ord($byte) & 0x7F);

            if ((ord($byte) & 0x80) === 0) {
                $arcs[] = $value;
                $value = 0;
            }
        }

        $first = min(2, intdiv($arcs[0], 40));
        array_splice($arcs, 0, 1, [$first, $arcs[0] - 40 * $first]);

        return implode('.', $arcs);
    }

    public function generalizedTime(): CarbonImmutable
    {
        $content = $this->expect(Der::GENERALIZED_TIME)->content;

        // DER requires UTC ("Z"); fractions may have any precision, microseconds are kept.
        if (preg_match('/^(\d{14})(?:\.(\d+))?Z$/', $content, $match) !== 1) {
            throw new InvalidTimestampException('Expected a generalized time in UTC.');
        }

        $time = CarbonImmutable::createFromFormat('YmdHis', $match[1], 'UTC');

        // createFromFormat() rolls impossible dates over (month 13 becomes January).
        if ($time === null || $time->format('YmdHis') !== $match[1]) {
            throw new InvalidTimestampException('Invalid generalized time.');
        }

        return $time->setMicrosecond((int) str_pad(substr($match[2] ?? '0', 0, 6), 6, '0'));
    }
}
