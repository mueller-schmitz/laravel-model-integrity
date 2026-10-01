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

        foreach (str_split($content) as $byte) {
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

        // DER requires UTC ("Z") and no trailing zeros in fractions; accept fractions of any length.
        if (preg_match('/^(\d{14})(?:\.(\d{1,6}))?Z$/', $content, $match) !== 1) {
            throw new InvalidTimestampException('Expected a generalized time in UTC.');
        }

        $time = CarbonImmutable::createFromFormat('YmdHis', $match[1], 'UTC');

        if ($time === null) {
            throw new InvalidTimestampException('Invalid generalized time.');
        }

        return $time->setMicrosecond((int) str_pad($match[2] ?? '0', 6, '0'));
    }
}
