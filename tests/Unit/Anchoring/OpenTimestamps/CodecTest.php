<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Attestation;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Codec;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\DetachedTimestamp;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Op;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Timestamp;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

beforeEach(function (): void {
    $this->codec = new Codec;
    $this->fixtures = __DIR__.'/../../../Fixtures/opentimestamps';
    $this->digest = hash_file('sha256', $this->fixtures.'/fixture.txt', true);
});

const BITCOIN_ONE = '000588960d73d719010101'; // Bitcoin attestation, height 1
const PENDING_EXAMPLE = '0083dfe30d2ef90c8e141368747470733a2f2f6578616d706c652e636f6d'; // pending "https://example.com"

it('decodes and re-encodes real calendar responses byte for byte', function (string $file, string $uri): void {
    $bytes = (string) file_get_contents($this->fixtures.'/'.$file);

    $timestamp = $this->codec->decodeTimestamp($bytes, $this->digest);
    $attestations = $timestamp->allAttestations();

    expect($this->codec->encodeTimestamp($timestamp))->toBe($bytes)
        ->and($attestations)->toHaveCount(1)
        ->and($attestations[0][0]->uri())->toBe($uri);
})->with([
    ['alice-submit.bin', 'https://alice.btc.calendar.opentimestamps.org'],
    ['bob-submit.bin', 'https://bob.btc.calendar.opentimestamps.org'],
]);

it('decodes a hand-written timestamp', function (): void {
    // append 0x0102, sha256, pending attestation
    $bytes = hex2bin('f0020102'.'08'.PENDING_EXAMPLE);

    $timestamp = $this->codec->decodeTimestamp($bytes, $this->digest);
    [[$attestation, $message]] = $timestamp->allAttestations();

    expect($attestation->isPending())->toBeTrue()
        ->and($attestation->uri())->toBe('https://example.com')
        ->and($message)->toBe(hash('sha256', $this->digest."\x01\x02", true))
        ->and($this->codec->encodeTimestamp($timestamp))->toBe($bytes);
});

it('encodes forks with 0xff before every entry but the last, attestations first', function (): void {
    $timestamp = new Timestamp($this->digest);
    $timestamp->add(Op::sha256())->attest(Attestation::bitcoin(358391));
    $timestamp->attest(Attestation::pending('https://example.com'));

    $bytes = $this->codec->encodeTimestamp($timestamp);

    expect(bin2hex($bytes))->toBe('ff'.PENDING_EXAMPLE.'08'.'000588960d73d7190103f7ef15')
        ->and($this->codec->encodeTimestamp($this->codec->decodeTimestamp($bytes, $this->digest)))->toBe($bytes);
});

it('merges timestamps of several calendars into one tree', function (): void {
    $alice = $this->codec->decodeTimestamp((string) file_get_contents($this->fixtures.'/alice-submit.bin'), $this->digest);
    $bob = $this->codec->decodeTimestamp((string) file_get_contents($this->fixtures.'/bob-submit.bin'), $this->digest);

    $alice->merge($bob);
    $uris = array_map(fn (array $entry): ?string => $entry[0]->uri(), $alice->allAttestations());
    sort($uris);

    expect($uris)->toBe(['https://alice.btc.calendar.opentimestamps.org', 'https://bob.btc.calendar.opentimestamps.org'])
        ->and($this->codec->decodeTimestamp($this->codec->encodeTimestamp($alice), $this->digest)->allAttestations())->toHaveCount(2);
});

it('tells whether a timestamp contains another one', function (): void {
    $alice = $this->codec->decodeTimestamp((string) file_get_contents($this->fixtures.'/alice-submit.bin'), $this->digest);
    $merged = $this->codec->decodeTimestamp((string) file_get_contents($this->fixtures.'/alice-submit.bin'), $this->digest);
    $merged->merge($this->codec->decodeTimestamp((string) file_get_contents($this->fixtures.'/bob-submit.bin'), $this->digest));

    expect($merged->contains($alice))->toBeTrue()
        ->and($alice->contains($merged))->toBeFalse();
});

it('writes and reads detached timestamp files', function (): void {
    $timestamp = $this->codec->decodeTimestamp((string) file_get_contents($this->fixtures.'/alice-submit.bin'), $this->digest);

    $file = $this->codec->encodeDetached(new DetachedTimestamp($this->digest, $timestamp));
    $read = $this->codec->decodeDetached($file);

    expect(bin2hex(substr($file, 0, 31)))->toBe('004f70656e54696d657374616d7073000050726f6f6600bf89e2e884e89294')
        ->and(bin2hex(substr($file, 31, 2)))->toBe('0108')
        ->and(substr($file, 33, 32))->toBe($this->digest)
        ->and($read->digest)->toBe($this->digest)
        ->and($this->codec->encodeDetached($read))->toBe($file);
});

it('applies the operations of the specification', function (Op $op, string $input, string $output): void {
    expect(bin2hex($op->apply($input)))->toBe($output);
})->with([
    'sha256' => [fn () => Op::sha256(), 'abc', hash('sha256', 'abc')],
    'sha1' => [fn () => Op::sha1(), 'abc', hash('sha1', 'abc')],
    'ripemd160' => [fn () => Op::ripemd160(), 'abc', hash('ripemd160', 'abc')],
    'append' => [fn () => Op::append('de'), 'abc', bin2hex('abcde')],
    'prepend' => [fn () => Op::prepend('de'), 'abc', bin2hex('deabc')],
    'reverse' => [fn () => Op::reverse(), 'abc', bin2hex('cba')],
    'hexlify' => [fn () => Op::hexlify(), "\x01\xab", bin2hex('01ab')],
]);

it('rejects malformed timestamps', function (string $hex): void {
    (new Codec)->decodeTimestamp((string) hex2bin($hex), str_repeat("\x00", 32));
})->with([
    'empty' => [''],
    'truncated attestation' => ['0083dfe30d2ef90c8e14'],
    'trailing bytes' => [PENDING_EXAMPLE.'00'],
    'unknown op' => ['99'.PENDING_EXAMPLE],
    'keccak256 is not supported' => ['67'.PENDING_EXAMPLE],
    'empty append argument' => ['f000'.PENDING_EXAMPLE],
    'uri with invalid characters' => ['0083dfe30d2ef90c8e0a09687474703a2f2f613f'], // "http://a?"
    'varuint overflow' => ['f0ffffffffffffffffffff01'],
    'fork without second entry' => ['ff'.PENDING_EXAMPLE],
])->throws(InvalidTimestampException::class);

it('rejects too deeply nested timestamps', function (): void {
    (new Codec)->decodeTimestamp((string) hex2bin(str_repeat('08', 300).BITCOIN_ONE), str_repeat("\x00", 32));
})->throws(InvalidTimestampException::class, 'deep');

it('rejects messages that grow beyond the limit', function (): void {
    $append = 'f0'.'a01f'.str_repeat('aa', 4000); // varuint 4000
    (new Codec)->decodeTimestamp((string) hex2bin($append.$append.BITCOIN_ONE), str_repeat("\x00", 32));
})->throws(InvalidTimestampException::class);

it('rejects detached files with a wrong header, version or hash op', function (string $hex): void {
    (new Codec)->decodeDetached((string) hex2bin($hex.str_repeat('00', 32).PENDING_EXAMPLE));
})->with([
    'magic' => ['ff4f70656e54696d657374616d7073000050726f6f6600bf89e2e884e89294'.'0108'],
    'version' => ['004f70656e54696d657374616d7073000050726f6f6600bf89e2e884e89294'.'0208'],
    'hash op' => ['004f70656e54696d657374616d7073000050726f6f6600bf89e2e884e89294'.'0102'],
])->throws(InvalidTimestampException::class);

it('rejects proofs larger than any real proof', function (): void {
    (new Codec)->decodeTimestamp(str_repeat("\x00", Codec::MAX_SIZE + 1), str_repeat("\x00", 32));
})->throws(InvalidTimestampException::class, 'larger');

/**
 * A node with the given number of forks, each an append with its own argument
 * followed by an attestation.
 */
function forks(int $count): string
{
    $hex = '';

    for ($i = 0; $i < $count; $i++) {
        $hex .= 'ff'.'f002'.sprintf('%04x', $i).BITCOIN_ONE;
    }

    return $hex.BITCOIN_ONE;
}

it('rejects proofs whose nodes hold too many bytes in total', function (): void {
    // One append to 4000 bytes, then thousands of forks: each node keeps a 4 KB message.
    $hex = 'f0'.'a01f'.str_repeat('aa', 4000).forks(1000);

    (new Codec)->decodeTimestamp((string) hex2bin($hex), str_repeat("\x00", 32));
})->throws(InvalidTimestampException::class, 'too many');

it('reads a deep and wide proof in linear time', function (): void {
    $hex = str_repeat('f2', 250).forks(2000);
    $started = microtime(true);

    $timestamp = (new Codec)->decodeTimestamp((string) hex2bin($hex), str_repeat("\x00", 32));

    expect(count($timestamp->allAttestations()))->toBe(2001)
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
});
