<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\EsploraClient;

beforeEach(function (): void {
    $fixtures = __DIR__.'/../../../Fixtures/opentimestamps';
    $this->hash = trim((string) file_get_contents($fixtures.'/block-358391.hash'));
    $this->header = trim((string) file_get_contents($fixtures.'/block-358391.header'));
    config(['model-integrity.anchors.opentimestamps.esplora_url' => 'https://esplora.example/api']);
});

it('reads merkle root and time from the block header', function (): void {
    Http::fake([
        'esplora.example/api/block-height/358391' => Http::response($this->hash),
        "esplora.example/api/block/{$this->hash}/header" => Http::response($this->header),
    ]);

    $block = app(EsploraClient::class)->block(358391);

    expect(bin2hex($block->merkleRoot))->toBe('007ee445d23ad061af4a36b809501fab1ac4f2d7e7a739817dd0cbb7ec661b8a')
        ->and($block->time->toIso8601String())->toBe('2015-05-28T15:41:18+00:00')
        ->and($block->height)->toBe(358391);
});

it('asks for each block only once', function (): void {
    Http::fake([
        '*/block-height/*' => Http::response($this->hash),
        '*/header' => Http::response($this->header),
    ]);
    $client = app(EsploraClient::class);

    $client->block(358391);
    $client->block(358391);

    Http::assertSentCount(2);
});

it('rejects a header that does not belong to the block hash', function (): void {
    Http::fake([
        '*/block-height/*' => Http::response($this->hash),
        '*/header' => Http::response(substr_replace($this->header, 'ff', 72, 2)),
    ]);

    app(EsploraClient::class)->block(358391);
})->throws(RuntimeException::class, 'does not match');

it('fails when the block source cannot answer', function (int $status, string $body): void {
    Http::fake(['*' => Http::response($body, $status)]);

    app(EsploraClient::class)->block(358391);
})->with([
    'unknown height' => [404, 'Block not found'],
    'not a hash' => [200, 'garbage'],
])->throws(RuntimeException::class);

it('fails without a configured block source', function (): void {
    config(['model-integrity.anchors.opentimestamps.esplora_url' => null]);

    app(EsploraClient::class)->block(358391);
})->throws(RuntimeException::class, 'esplora_url');
