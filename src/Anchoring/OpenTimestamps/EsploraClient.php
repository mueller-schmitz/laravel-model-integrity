<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Reads Bitcoin block headers from an Esplora API (blockstream.info,
 * mempool.space or a self-hosted electrs).
 *
 * The raw header is checked against the block hash, so the API cannot hand
 * out a header of another block for the hash it names. Which block is at a
 * height is taken from the API; run your own node to depend on no one.
 */
class EsploraClient
{
    /** @var array<int, BitcoinBlock> */
    private array $blocks = [];

    public function __construct(
        private readonly Factory $http,
    ) {}

    public function block(int $height): BitcoinBlock
    {
        return $this->blocks[$height] ??= $this->fetch($height);
    }

    private function fetch(int $height): BitcoinBlock
    {
        $hash = trim($this->get("block-height/{$height}")->body());

        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new RuntimeException("The block source returned no block hash for height {$height}.");
        }

        $header = hex2bin(trim($this->get("block/{$hash}/header")->body()));

        if ($header === false || strlen($header) !== 80) {
            throw new RuntimeException("The block source returned no 80-byte header for block {$hash}.");
        }

        // The block hash is the double SHA-256 of the header, displayed in reverse byte order.
        if (bin2hex(strrev(hash('sha256', hash('sha256', $header, true), true))) !== $hash) {
            throw new RuntimeException("The header the block source returned does not match block {$hash}.");
        }

        /** @var array{1: int} $time */
        $time = unpack('V', substr($header, 68, 4));

        return new BitcoinBlock($height, substr($header, 36, 32), CarbonImmutable::createFromTimestampUTC($time[1]));
    }

    private function get(string $path): Response
    {
        $base = config('model-integrity.anchors.opentimestamps.esplora_url');

        if (! is_string($base) || $base === '') {
            throw new RuntimeException('No block source configured: set model-integrity.anchors.opentimestamps.esplora_url to check Bitcoin attestations.');
        }

        $response = $this->http
            ->withHeaders(['User-Agent' => 'mueller-schmitz/laravel-model-integrity'])
            ->connectTimeout(Config::integer('model-integrity.anchors.opentimestamps.timeout', 10))
            ->timeout(Config::integer('model-integrity.anchors.opentimestamps.timeout', 10))
            ->get(rtrim($base, '/').'/'.$path);

        if (! $response->successful()) {
            throw new RuntimeException("The block source answered [{$path}] with HTTP {$response->status()}.");
        }

        return $response;
    }
}
