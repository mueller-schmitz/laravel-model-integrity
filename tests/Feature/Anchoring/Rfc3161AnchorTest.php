<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatus;
use MuellerSchmitz\ModelIntegrity\Anchoring\Drivers\Rfc3161Anchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\Der;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\TimeStampRequest;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;

/*
 * test.tsr is the test TSA's answer to statement.tsq, a request for the
 * statement below; the driver gets that request's nonce, as the TSA signed it.
 */

beforeEach(function (): void {
    if (! function_exists('openssl_cms_verify')) {
        $this->markTestSkipped('ext-openssl is not available.');
    }

    $this->fixtures = __DIR__.'/../../Fixtures/rfc3161';
    $this->statement = new AnchorStatement(1, 1, 3, str_repeat('a', 64), null);
    $this->nonce = Der::decode((string) file_get_contents($this->fixtures.'/statement.tsq'))->children()[2]->unsignedBytes();
    $this->response = (string) file_get_contents($this->fixtures.'/test.tsr');

    config(['model-integrity.anchors.rfc3161' => [
        'url' => 'https://tsa.example/tsr',
        'ca_file' => $this->fixtures.'/test-ca.pem',
        'intermediates_file' => null,
        'policy' => null,
        'timeout' => 5,
        'headers' => ['Authorization' => 'Bearer secret'],
    ]]);
});

function rfc3161Driver(string $nonce, ?string $policy = null): Rfc3161Anchor
{
    return new Rfc3161Anchor(
        app(Factory::class),
        'https://tsa.example/tsr',
        config('model-integrity.anchors.rfc3161.ca_file'),
        null,
        $policy,
        5,
        ['Authorization' => 'Bearer secret'],
        fn (string $digest): TimeStampRequest => new TimeStampRequest($digest, $nonce),
    );
}

it('is created by the anchor manager from the config', function (): void {
    expect(app(AnchorManager::class)->driver('rfc3161'))->toBeInstanceOf(Rfc3161Anchor::class);
});

it('requires a URL and a CA file', function (string $key): void {
    config(["model-integrity.anchors.rfc3161.{$key}" => null]);

    app(AnchorManager::class)->driver('rfc3161');
})->with(['url', 'ca_file'])->throws(IntegrityConfigurationException::class);

it('requests a time-stamp and keeps the response as proof', function (): void {
    Http::fake(['tsa.example/*' => Http::response($this->response)]);

    $proof = rfc3161Driver($this->nonce)->submit($this->statement);

    expect($proof)->toBe($this->response);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tsa.example/tsr'
        && $request->header('Content-Type')[0] === 'application/timestamp-query'
        && $request->header('Authorization')[0] === 'Bearer secret'
        && $request->body() === (new TimeStampRequest((string) hex2bin($this->statement->digest()), $this->nonce))->encode());
});

it('rejects a response to another request', function (): void {
    // e.g. a replayed or cached response: the nonce differs.
    Http::fake(['*' => Http::response($this->response)]);

    rfc3161Driver("\x01\x02\x03")->submit($this->statement);
})->throws(RuntimeException::class, 'nonce');

it('rejects a response for another policy than required', function (): void {
    Http::fake(['*' => Http::response($this->response)]);

    rfc3161Driver($this->nonce, '1.2.3.4.99')->submit($this->statement);
})->throws(RuntimeException::class, 'policy');

it('fails when the TSA refuses or errs', function (int $status, string $body): void {
    Http::fake(['*' => Http::response($body, $status)]);

    rfc3161Driver($this->nonce)->submit($this->statement);
})->with([
    'http error' => [fn () => 500, fn () => ''],
    'rejection' => [fn () => 200, fn () => Der::sequence(Der::sequence(Der::integer(2), Der::sequence("\x0c\x0cbad request")))],
    'not a response' => [fn () => 200, fn () => 'garbage'],
])->throws(RuntimeException::class);

it('confirms a valid proof with the time of the time-stamp', function (): void {
    $verification = rfc3161Driver($this->nonce)->verify($this->statement, $this->response);

    expect($verification->status)->toBe(AnchorStatus::Confirmed)
        ->and($verification->attestedAt?->format('Y-m-d H:i:s'))->toBe('2026-10-01 11:59:06');
});

it('rejects proofs that do not attest the statement', function (string $proof, ?AnchorStatement $statement): void {
    $statement ??= $this->statement;

    expect(rfc3161Driver($this->nonce)->verify($statement, $proof)->status)->toBe(AnchorStatus::Invalid);
})->with([
    'another statement' => [fn () => (string) file_get_contents(__DIR__.'/../../Fixtures/rfc3161/test.tsr'), fn () => new AnchorStatement(1, 1, 3, str_repeat('b', 64), null)],
    'foreign TSA' => [fn () => (string) file_get_contents(__DIR__.'/../../Fixtures/rfc3161/foreign.tsr'), null],
    'not a response' => ['garbage', null],
]);

it('exports proofs as .tsr files', function (): void {
    expect(rfc3161Driver($this->nonce)->proofFileExtension())->toBe('tsr');
});
