<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Attestation;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\CalendarClient;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Codec;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Op;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Timestamp;

beforeEach(function (): void {
    $this->fixtures = __DIR__.'/../../../Fixtures/opentimestamps';
    $this->digest = hash_file('sha256', $this->fixtures.'/fixture.txt', true);
    $this->client = app(CalendarClient::class);
});

it('submits a digest and reads the timestamp the calendar returns', function (): void {
    Http::fake(['alice.example/digest' => Http::response((string) file_get_contents($this->fixtures.'/alice-submit.bin'))]);

    $timestamp = $this->client->submit('https://alice.example', $this->digest);

    expect($timestamp->message)->toBe($this->digest)
        ->and($timestamp->allAttestations()[0][0]->isPending())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://alice.example/digest'
        && $request->body() === $this->digest
        && $request->header('Accept')[0] === 'application/vnd.opentimestamps.v1');
});

it('asks for the upgrade of a commitment', function (): void {
    $commitment = random_bytes(32);
    $upgrade = (new Timestamp($commitment));
    $upgrade->add(Op::sha256())->attest(Attestation::bitcoin(900000));
    Http::fake(['*' => Http::response((new Codec)->encodeTimestamp($upgrade))]);

    $timestamp = $this->client->upgrade('https://alice.example/', $commitment);

    expect($timestamp?->allAttestations()[0][0]->height())->toBe(900000);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://alice.example/timestamp/'.bin2hex($commitment));
});

it('returns no upgrade while the calendar has none yet', function (): void {
    Http::fake(['*' => Http::response('Pending confirmation in Bitcoin blockchain', 404)]);

    expect($this->client->upgrade('https://alice.example', random_bytes(32)))->toBeNull();
});

it('fails on errors and invalid responses', function (int $status, string $body): void {
    Http::fake(['*' => Http::response($body, $status)]);

    $this->client->submit('https://alice.example', $this->digest);
})->with([
    'server error' => [500, ''],
    'not a timestamp' => [200, '<html>maintenance</html>'],
    'too large' => [200, str_repeat("\x08", 20_000)],
])->throws(RuntimeException::class);
