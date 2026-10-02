<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Codec;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\DetachedTimestamp;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\EsploraClient;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Timestamp;

/*
 * A real proof: fixture.txt was submitted to the alice and bob calendars on
 * 2026-10-01 (*-submit.bin), and the upgrades they returned once the
 * commitments were in Bitcoin (*-upgrade.bin). The block headers were read
 * from blockstream.info.
 */

beforeEach(function (): void {
    $this->fixtures = __DIR__.'/../../../Fixtures/opentimestamps';
    $this->digest = hash_file('sha256', $this->fixtures.'/fixture.txt', true);

    Http::fake(function ($request) {
        foreach ([969440, 969456] as $height) {
            $hash = (string) file_get_contents($this->fixtures."/block-{$height}.hash");

            if (str_ends_with($request->url(), "/block-height/{$height}")) {
                return Http::response($hash);
            }

            if (str_ends_with($request->url(), "/block/{$hash}/header")) {
                return Http::response((string) file_get_contents($this->fixtures."/block-{$height}.header"));
            }
        }

        return Http::response('', 404);
    });
    config(['model-integrity.anchors.opentimestamps.esplora_url' => 'https://esplora.example/api']);
});

/**
 * The submitted timestamps with the calendars' upgrades merged in, as
 * model-integrity:anchor-upgrade does.
 */
function completedRealProof(string $fixtures, string $digest): Timestamp
{
    $codec = new Codec;
    $timestamp = new Timestamp($digest);

    foreach (['alice', 'bob'] as $calendar) {
        $submitted = $codec->decodeTimestamp((string) file_get_contents("{$fixtures}/{$calendar}-submit.bin"), $digest);
        [[, $commitment]] = $submitted->allAttestations();
        $upgrade = $codec->decodeTimestamp((string) file_get_contents("{$fixtures}/{$calendar}-upgrade.bin"), $commitment);

        foreach ($submitted->nodesFor($commitment) as $node) {
            $node->merge($upgrade);
        }

        $timestamp->merge($submitted);
    }

    return $timestamp;
}

it('leads to the merkle roots of the real Bitcoin blocks', function (int $height, string $time): void {
    $attestations = array_filter(
        completedRealProof($this->fixtures, $this->digest)->allAttestations(),
        fn (array $entry): bool => $entry[0]->height() === $height,
    );
    [[, $message]] = array_values($attestations);

    $block = app(EsploraClient::class)->block($height);

    expect($block->merkleRoot)->toBe($message)
        ->and($block->time->toIso8601String())->toBe($time);
})->with([
    'alice' => [969440, '2026-10-01T10:28:11+00:00'],
    'bob' => [969456, '2026-10-01T14:52:16+00:00'],
]);

it('keeps the real proof intact when written as .ots file and read again', function (): void {
    $codec = new Codec;
    $file = $codec->encodeDetached(new DetachedTimestamp($this->digest, completedRealProof($this->fixtures, $this->digest)));

    $heights = array_values(array_filter(array_map(fn (array $entry): ?int => $entry[0]->height(), $codec->decodeDetached($file)->timestamp->allAttestations())));
    sort($heights);

    expect($heights)->toBe([969440, 969456])
        ->and($codec->encodeDetached($codec->decodeDetached($file)))->toBe($file);
});
