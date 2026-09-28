<?php

declare(strict_types=1);

use Illuminate\Contracts\Support\Arrayable;
use MuellerSchmitz\ModelIntegrity\Exceptions\CanonicalizationException;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;

enum CanonicalTestStatus: string
{
    case Paid = 'paid';
}

enum CanonicalTestPriority: int
{
    case High = 1;
}

enum CanonicalTestFlag
{
    case Archived;
}

beforeEach(function (): void {
    $this->serializer = new CanonicalSerializer;
});

describe('scalars', function (): void {
    it('keeps null, booleans and integers native', function (): void {
        expect($this->serializer->encode([null, true, false, 0, -42, PHP_INT_MAX]))
            ->toBe('[null,true,false,0,-42,'.PHP_INT_MAX.']');
    });

    it('keeps strings unchanged', function (): void {
        expect($this->serializer->normalize(['', ' padded ', '0', '12.50']))
            ->toBe(['', ' padded ', '0', '12.50']);
    });

    it('encodes strings without escaping slashes or unicode', function (): void {
        expect($this->serializer->encode(['Müller/Schmitz', '€', '"quoted"', "line\nbreak", '']))
            ->toBe('["Müller/Schmitz","€","\"quoted\"","line\nbreak",""]');
    });

    it('escapes only what json requires', function (): void {
        // Line and paragraph separators and DEL stay raw, control characters use
        // the short escapes where available and lowercase \u00xx otherwise.
        expect($this->serializer->encode(["\u{2028}\u{2029}\x7F", "\x1F\t\r\x08\x0C\\"]))
            ->toBe("[\"\u{2028}\u{2029}\x7F\",\"\\u001f\\t\\r\\b\\f\\\\\"]");
    });

    it('converts floats to their shortest round-trip string', function (float $value, string $expected): void {
        expect($this->serializer->normalize($value))->toBe($expected);
    })->with([
        [0.1, '0.1'],
        [1.0, '1'],
        [-2.5, '-2.5'],
        [12.50, '12.5'],
        [100.0, '100'],
        [123456.0, '123456'],
        [0.1 + 0.2, '0.30000000000000004'],
        [0.00001, '0.00001'],
        [0.0000001, '0.0000001'],
        [0.00000001, '1E-8'],
        [1.0E+20, '100000000000000000000'],
        [1.0E+21, '1E+21'],
        [1.5E+25, '1.5E+25'],
        [-0.0, '0'],
        [5.0E-324, '5E-324'],
        [PHP_FLOAT_MAX, '1.7976931348623157E+308'],
        // Beyond 2^53 the exact decimal expansion has more digits than the
        // shortest round-trip form; the shortest form is used, like JSON.stringify.
        [1.2345678901234568E+20, '123456789012345680000'],
        [9007199254740994.0, '9007199254740994'],
        [-1.5E+18, '-1500000000000000000'],
        [123456789.123456789, '123456789.12345679'],
        [-0.000001, '-0.000001'],
    ]);

    it('formats floats independently of the precision ini settings', function (): void {
        $precision = ini_get('precision');
        $serializePrecision = ini_get('serialize_precision');

        try {
            ini_set('precision', '3');
            ini_set('serialize_precision', '5');

            expect($this->serializer->normalize([0.1 + 0.2, 123456.0]))->toBe(['0.30000000000000004', '123456']);
        } finally {
            ini_set('precision', (string) $precision);
            ini_set('serialize_precision', (string) $serializePrecision);
        }
    });

    it('formats floats independently of the locale', function (): void {
        $locale = setlocale(LC_NUMERIC, '0');

        try {
            if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German_Germany.1252', 'deu') === false) {
                $this->markTestSkipped('No German locale available.');
            }

            expect(sprintf('%.1f', 1234.5))->toBe('1234,5')
                ->and($this->serializer->normalize(1234.5))->toBe('1234.5');
        } finally {
            setlocale(LC_NUMERIC, (string) $locale);
        }
    });

    it('rejects non-finite floats', function (float $value): void {
        $this->serializer->normalize($value);
    })->with([NAN, INF, -INF])->throws(CanonicalizationException::class);

    it('rejects invalid utf-8 strings', function (): void {
        $this->serializer->normalize(['avatar' => "\xFF\xFE"]);
    })->throws(CanonicalizationException::class, 'avatar');
});

describe('dates', function (): void {
    it('converts dates to utc with microseconds', function (): void {
        $date = new DateTimeImmutable('2026-09-28 12:30:45.123456', new DateTimeZone('Europe/Berlin'));

        expect($this->serializer->normalize($date))->toBe('2026-09-28T10:30:45.123456Z');
    });

    it('pads missing microseconds', function (): void {
        $date = new DateTime('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        expect($this->serializer->normalize($date))->toBe('2026-01-01T00:00:00.000000Z');
    });

    it('does not modify the given date instance', function (): void {
        $date = new DateTime('2026-09-28 12:00:00', new DateTimeZone('Europe/Berlin'));

        $this->serializer->normalize($date);

        expect($date->getTimezone()->getName())->toBe('Europe/Berlin');
    });
});

describe('objects', function (): void {
    it('converts enums', function (): void {
        expect($this->serializer->normalize([
            CanonicalTestStatus::Paid,
            CanonicalTestPriority::High,
            CanonicalTestFlag::Archived,
        ]))->toBe(['paid', 1, 'Archived']);
    });

    it('converts arrayable and json serializable objects recursively', function (): void {
        $arrayable = new class implements Arrayable
        {
            public function toArray(): array
            {
                return ['b' => 1.5, 'a' => new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'))];
            }
        };

        $jsonSerializable = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['z' => true, 'y' => CanonicalTestStatus::Paid];
            }
        };

        expect($this->serializer->normalize(['first' => $arrayable, 'second' => $jsonSerializable]))->toBe([
            'first' => ['a' => '2026-01-01T00:00:00.000000Z', 'b' => '1.5'],
            'second' => ['y' => 'paid', 'z' => true],
        ]);
    });

    it('rejects other objects', function (): void {
        $this->serializer->normalize(['payload' => new stdClass]);
    })->throws(CanonicalizationException::class, 'payload');

    it('rejects resources', function (): void {
        $handle = fopen('php://memory', 'r');

        try {
            $this->serializer->normalize(['file' => $handle]);
        } finally {
            fclose($handle);
        }
    })->throws(CanonicalizationException::class, 'file');

    it('names the full path of a rejected value', function (): void {
        $this->serializer->normalize(['meta' => ['items' => [1, new stdClass]]]);
    })->throws(CanonicalizationException::class, 'meta.items.1');
});

describe('arrays', function (): void {
    it('sorts keys recursively by byte order', function (): void {
        $value = [
            'b' => 1,
            'a' => ['z' => 1, 'Z' => 2, 'ä' => 3, 'a' => 4],
            'B' => 2,
            '10' => 'ten',
            '9' => 'nine',
        ];

        expect($this->serializer->encode($value))
            ->toBe('{"10":"ten","9":"nine","B":2,"a":{"Z":2,"a":4,"z":1,"ä":3},"b":1}');
    });

    it('keeps the order of lists', function (): void {
        expect($this->serializer->encode(['c', 'a', 'b', ['y' => 1, 'x' => 2]]))
            ->toBe('["c","a","b",{"x":2,"y":1}]');
    });

    it('encodes an empty array as an empty list', function (): void {
        expect($this->serializer->encode(['items' => [], 'meta' => []]))
            ->toBe('{"items":[],"meta":[]}');
    });

    it('produces identical output regardless of insertion order', function (): void {
        $first = ['id' => 1, 'amount' => '12.50', 'meta' => ['x' => 1, 'y' => [2, 3]]];
        $second = ['meta' => ['y' => [2, 3], 'x' => 1], 'amount' => '12.50', 'id' => 1];

        expect($this->serializer->encode($first))->toBe($this->serializer->encode($second));
    });

    it('treats a sparse integer keyed array as a map', function (): void {
        expect($this->serializer->encode([2 => 'b', 0 => 'a']))->toBe('{"0":"a","2":"b"}');
    });
});
