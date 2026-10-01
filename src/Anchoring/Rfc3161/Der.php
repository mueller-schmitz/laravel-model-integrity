<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161;

use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

/**
 * The part of ASN.1 DER that RFC 3161 messages need: definite lengths,
 * single-byte identifiers.
 */
final class Der
{
    public const int BOOLEAN = 0x01;

    public const int INTEGER = 0x02;

    public const int OCTET_STRING = 0x04;

    public const int NULL = 0x05;

    public const int OID = 0x06;

    public const int UTF8_STRING = 0x0C;

    public const int GENERALIZED_TIME = 0x18;

    public const int SEQUENCE = 0x30;

    public const int SET = 0x31;

    /** Time-stamp responses with certificates have a few kilobytes. */
    public const int MAX_SIZE = 65_536;

    public static function decode(string $bytes): DerNode
    {
        $nodes = self::decodeAll($bytes);

        if (count($nodes) !== 1) {
            throw new InvalidTimestampException('Expected exactly one DER element.');
        }

        return $nodes[0];
    }

    /**
     * @return list<DerNode>
     */
    public static function decodeAll(string $bytes): array
    {
        if (strlen($bytes) > self::MAX_SIZE) {
            throw new InvalidTimestampException('The DER data is larger than '.self::MAX_SIZE.' bytes.');
        }

        $nodes = [];
        $position = 0;
        $total = strlen($bytes);

        while ($position < $total) {
            $start = $position;
            $tag = ord($bytes[$position++]);

            if (($tag & 0x1F) === 0x1F) {
                throw new InvalidTimestampException('Multi-byte DER identifiers are not supported.');
            }

            if ($position >= $total) {
                throw new InvalidTimestampException('The DER data ends unexpectedly.');
            }

            $length = ord($bytes[$position++]);

            if ($length === 0x80) {
                throw new InvalidTimestampException('Indefinite lengths are not allowed in DER.');
            }

            if ($length > 0x80) {
                $count = $length & 0x7F;

                if ($count > 4 || $position + $count > $total) {
                    throw new InvalidTimestampException('Invalid DER length.');
                }

                $length = (int) hexdec(bin2hex(substr($bytes, $position, $count)));
                $position += $count;
            }

            if ($position + $length > $total) {
                throw new InvalidTimestampException('A DER element is longer than its data.');
            }

            $nodes[] = new DerNode($tag, substr($bytes, $position, $length), substr($bytes, $start, $position + $length - $start));
            $position += $length;
        }

        if ($nodes === []) {
            throw new InvalidTimestampException('The DER data is empty.');
        }

        return $nodes;
    }

    public static function element(int $tag, string $content): string
    {
        $length = strlen($content);

        if ($length < 0x80) {
            return chr($tag).chr($length).$content;
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr($tag).chr(0x80 | strlen($bytes)).$bytes.$content;
    }

    public static function sequence(string ...$elements): string
    {
        return self::element(self::SEQUENCE, implode('', $elements));
    }

    public static function integer(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Only non-negative integers are supported.');
        }

        return self::unsignedInteger((string) hex2bin(str_pad(dechex($value), 2 * (int) ceil(strlen(dechex($value)) / 2), '0', STR_PAD_LEFT)));
    }

    /**
     * A non-negative INTEGER from its big-endian magnitude.
     */
    public static function unsignedInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return self::element(self::INTEGER, $bytes);
    }

    public static function oid(string $dotted): string
    {
        $arcs = array_map(intval(...), explode('.', $dotted));

        if (count($arcs) < 2) {
            throw new InvalidArgumentException("Invalid object identifier [{$dotted}].");
        }

        $values = [40 * $arcs[0] + $arcs[1], ...array_slice($arcs, 2)];
        $content = '';

        foreach ($values as $value) {
            $chunk = chr($value & 0x7F);

            while (($value >>= 7) > 0) {
                $chunk = chr(0x80 | ($value & 0x7F)).$chunk;
            }

            $content .= $chunk;
        }

        return self::element(self::OID, $content);
    }

    public static function null(): string
    {
        return "\x05\x00";
    }

    public static function boolean(bool $value): string
    {
        return self::element(self::BOOLEAN, $value ? "\xff" : "\x00");
    }

    public static function octetString(string $bytes): string
    {
        return self::element(self::OCTET_STRING, $bytes);
    }
}
