<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\Der;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\TimeStampRequest;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\TimeStampResponse;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

/*
 * statement.tsq was created by `openssl ts -query -digest <digest> -sha256 -cert`
 * for the statement below; freetsa.tsr is freetsa.org's answer to it, test.tsr
 * the answer of a test TSA (tests/Fixtures/rfc3161).
 */

beforeEach(function (): void {
    $this->fixtures = __DIR__.'/../../../Fixtures/rfc3161';
    $this->digest = (string) hex2bin((new AnchorStatement(1, 1, 3, str_repeat('a', 64), null))->digest());
});

it('builds the same request as openssl', function (): void {
    $openssl = (string) file_get_contents($this->fixtures.'/statement.tsq');
    // The nonce is random; take openssl's.
    $nonce = Der::decode($openssl)->children()[2]->content;

    expect((new TimeStampRequest($this->digest, $nonce))->encode())->toBe($openssl);
});

it('creates requests with a random positive nonce', function (): void {
    $first = TimeStampRequest::for($this->digest);
    $second = TimeStampRequest::for($this->digest);

    expect($first->nonce)->not->toBe($second->nonce)
        ->and(strlen($first->nonce))->toBe(8)
        // unsignedBytes() rejects negative integers.
        ->and(Der::decode($first->encode())->children()[2]->unsignedBytes())->toBe(ltrim($first->nonce, "\x00"));
});

it('reads a real response from freetsa.org', function (): void {
    $response = TimeStampResponse::decode((string) file_get_contents($this->fixtures.'/freetsa.tsr'));
    $info = $response->info();

    expect($response->status)->toBe(0)
        ->and($info->policy)->toBe('1.2.3.4.1')
        ->and($info->hashAlgorithm)->toBe('2.16.840.1.101.3.4.2.1')
        ->and($info->hashedMessage)->toBe($this->digest)
        ->and(bin2hex($info->serialNumber))->toBe('08be58d9')
        ->and($info->genTime->format('Y-m-d H:i:s'))->toBe('2026-10-01 11:58:29')
        ->and(bin2hex((string) $info->nonce))->toBe('260e0871a4b0bd3f');
});

it('reads a response with accuracy and without TSA name', function (): void {
    $info = TimeStampResponse::decode((string) file_get_contents($this->fixtures.'/test.tsr'))->info();

    expect($info->genTime->format('Y-m-d H:i:s'))->toBe('2026-10-01 11:59:06')
        ->and(bin2hex($info->serialNumber))->toBe('02');
});

it('reads a rejection without a token', function (): void {
    // status rejection (2), statusString "bad"
    $bytes = Der::sequence(Der::sequence(Der::integer(2), Der::sequence("\x0c\x03bad")));

    $response = TimeStampResponse::decode($bytes);

    expect($response->status)->toBe(2)
        ->and($response->token)->toBeNull()
        ->and($response->statusText)->toBe('bad')
        ->and(fn () => $response->info())->toThrow(InvalidTimestampException::class);
});

it('rejects responses that are no time-stamp responses', function (string $bytes): void {
    TimeStampResponse::decode($bytes);
})->with([
    'garbage' => ['garbage'],
    'no status' => [fn () => Der::sequence(Der::null())],
])->throws(InvalidTimestampException::class);
