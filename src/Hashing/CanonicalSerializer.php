<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Hashing;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use MuellerSchmitz\ModelIntegrity\Exceptions\CanonicalizationException;
use UnitEnum;

/**
 * Converts values into a canonical form and encodes them as JSON.
 *
 * The output is part of hash format 1 and must never change. Any change in
 * behaviour requires a new hash format.
 */
class CanonicalSerializer
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    // Escape only what JSON requires, so any standard JSON encoder can reproduce the output.
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_THROW_ON_ERROR;

    public function encode(mixed $value): string
    {
        return json_encode($this->normalize($value), self::JSON_FLAGS);
    }

    /**
     * @return null|bool|int|string|array<array-key, mixed>
     */
    public function normalize(mixed $value, string $path = '$'): null|bool|int|string|array
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => $this->normalizeFloat($value, $path),
            is_string($value) => $this->normalizeString($value, $path),
            is_array($value) => $this->normalizeArray($value, $path),
            $value instanceof DateTimeInterface => $this->normalizeDate($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Arrayable => $this->normalize($value->toArray(), $path),
            $value instanceof JsonSerializable => $this->normalize($value->jsonSerialize(), $path),
            default => throw CanonicalizationException::unsupportedType($path, get_debug_type($value)),
        };
    }

    /**
     * Shortest decimal string that parses back to the same float, independent of
     * the precision ini settings and the locale. Fixed notation is used for
     * exponents from -7 to 20, scientific notation (1.5E+25) otherwise.
     */
    private function normalizeFloat(float $value, string $path): string
    {
        if (! is_finite($value)) {
            throw CanonicalizationException::nonFiniteFloat($path);
        }

        if ($value === 0.0) {
            return '0';
        }

        for ($digits = 1; $digits < 17; $digits++) {
            if ((float) sprintf('%.'.($digits - 1).'e', $value) === $value) {
                break;
            }
        }

        [$mantissa, $exponent] = explode('e', sprintf('%.'.($digits - 1).'e', abs($value)));
        $exponent = (int) $exponent;
        $sign = $value < 0 ? '-' : '';

        if ($exponent < -7 || $exponent >= 21) {
            $mantissa = str_contains($mantissa, '.') ? rtrim(rtrim($mantissa, '0'), '.') : $mantissa;

            return $sign.$mantissa.'E'.($exponent < 0 ? '-' : '+').abs($exponent);
        }

        // Fixed notation built from the shortest digits, so large values keep
        // the round-trip form instead of their exact binary expansion.
        $significant = str_replace('.', '', $mantissa);

        if ($exponent < 0) {
            return $sign.'0.'.str_repeat('0', -$exponent - 1).$significant;
        }

        $integerDigits = $exponent + 1;

        return $sign.(strlen($significant) <= $integerDigits
            ? str_pad($significant, $integerDigits, '0')
            : substr($significant, 0, $integerDigits).'.'.substr($significant, $integerDigits));
    }

    private function normalizeString(string $value, string $path): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw CanonicalizationException::invalidUtf8($path);
        }

        return $value;
    }

    private function normalizeDate(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::DATE_FORMAT);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $value, string $path): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalize($item, $path.'.'.$key);
        }

        if (! array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }
}
