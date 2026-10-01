<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\Der;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

it('encodes the types a time-stamp request needs', function (string $encoded, string $hex): void {
    expect(bin2hex($encoded))->toBe($hex);
})->with([
    'integer 0' => [fn () => Der::integer(0), '020100'],
    'integer 127' => [fn () => Der::integer(127), '02017f'],
    'integer 128 gets a leading zero' => [fn () => Der::integer(128), '02020080'],
    'integer 256' => [fn () => Der::integer(256), '02020100'],
    'unsigned bytes' => [fn () => Der::unsignedInteger("\xff\x01"), '020300ff01'],
    'sha256 oid' => [fn () => Der::oid('2.16.840.1.101.3.4.2.1'), '0609608648016503040201'],
    'null' => [fn () => Der::null(), '0500'],
    'true' => [fn () => Der::boolean(true), '0101ff'],
    'octet string' => [fn () => Der::octetString("\x01\x02"), '04020102'],
    'sequence' => [fn () => Der::sequence(Der::null(), Der::integer(1)), '30050500020101'],
]);

it('encodes long lengths in the long form', function (): void {
    expect(bin2hex(substr(Der::octetString(str_repeat('a', 300)), 0, 4)))->toBe('0482012c');
});

it('decodes nested structures and their values', function (): void {
    $node = Der::decode(Der::sequence(Der::oid('1.2.3.4.1'), Der::integer(258), Der::boolean(false), Der::octetString('x')));
    [$oid, $integer, $boolean, $octets] = $node->children();

    expect($node->tag)->toBe(Der::SEQUENCE)
        ->and($oid->oid())->toBe('1.2.3.4.1')
        ->and($integer->integer())->toBe(258)
        ->and($boolean->boolean())->toBeFalse()
        ->and($octets->content)->toBe('x');
});

it('decodes generalized time in UTC, with and without fractions', function (string $time, string $iso): void {
    $node = Der::decode("\x18".chr(strlen($time)).$time);

    expect($node->generalizedTime()->format('Y-m-d\TH:i:s.u\Z'))->toBe($iso);
})->with([
    ['20261001115829Z', '2026-10-01T11:58:29.000000Z'],
    ['20261001115829.25Z', '2026-10-01T11:58:29.250000Z'],
]);

it('rejects malformed encodings', function (string $hex): void {
    Der::decode((string) hex2bin($hex))->children();
})->with([
    'empty' => [''],
    'length beyond the data' => ['3005020100'],
    'indefinite length' => ['30800201000000'],
    'trailing bytes' => ['05000500'],
    'child exceeding its parent' => ['3003020501'],
])->throws(InvalidTimestampException::class);

it('rejects generalized time without UTC designator', function (): void {
    Der::decode("\x18\x0e20261001115829")->generalizedTime();
})->throws(InvalidTimestampException::class, 'UTC');
