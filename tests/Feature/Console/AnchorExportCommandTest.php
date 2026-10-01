<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Anchoring\Anchorer;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Codec;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Support\OpenTimestampsFake as Ots;

beforeEach(function (): void {
    Ots::install();
    Storage::fake('anchors');
    config([
        'model-integrity.anchors.drivers' => ['disk', 'opentimestamps'],
        'model-integrity.anchors.disk' => ['disk' => 'anchors', 'path' => 'statements'],
    ]);

    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
    $this->anchor = app(Anchorer::class)->anchor()->anchor;
    $this->directory = sys_get_temp_dir().'/mi-export-'.bin2hex(random_bytes(4));
});

afterEach(fn () => File::deleteDirectory($this->directory));

it('writes the statement and an .ots file that standard tools can verify', function (): void {
    $this->artisan('model-integrity:anchor-export', ['anchor' => $this->anchor->id, 'directory' => $this->directory])
        ->expectsOutputToContain('ots verify')
        ->assertExitCode(0);

    $name = sprintf('%020d-%s.json', $this->anchor->to_sequence, $this->anchor->digest);
    $statement = (string) File::get($this->directory.'/'.$name);
    $ots = (new Codec)->decodeDetached((string) File::get($this->directory.'/'.$name.'.ots'));

    // `ots verify` hashes the statement file and compares it with the digest in the .ots file.
    expect(hash('sha256', $statement))->toBe($this->anchor->digest)
        ->and(bin2hex($ots->digest))->toBe($this->anchor->digest)
        ->and(File::files($this->directory))->toHaveCount(2);
});

it('exports the latest proof of each driver', function (): void {
    Ots::confirm(Ots::ALICE, hex2bin($this->anchor->digest), 900000, now('UTC')->toDateTimeString());
    $this->artisan('model-integrity:anchor-upgrade');

    $this->artisan('model-integrity:anchor-export', ['anchor' => $this->anchor->id, 'directory' => $this->directory])->assertExitCode(0);

    $ots = (new Codec)->decodeDetached((string) File::get($this->directory.'/'.sprintf('%020d-%s.json.ots', $this->anchor->to_sequence, $this->anchor->digest)));
    $heights = array_filter(array_map(fn (array $entry): ?int => $entry[0]->height(), $ots->timestamp->allAttestations()));

    expect(array_values($heights))->toBe([900000]);
});

it('fails for an unknown anchor', function (): void {
    $this->artisan('model-integrity:anchor-export', ['anchor' => 999999, 'directory' => $this->directory])
        ->expectsOutputToContain('999999')
        ->assertExitCode(2);
});

it('exports RFC 3161 proofs as .tsr files that openssl can verify', function (): void {
    $fixtures = __DIR__.'/../../Fixtures/rfc3161';
    $response = (string) file_get_contents($fixtures.'/test.tsr');
    // The fixture attests the statement (1, 1, 3, 'a' x 64, null); store an anchor for it.
    $id = DB::table('integrity_anchors')->insertGetId([
        'anchor_format' => 1, 'from_sequence' => 1, 'to_sequence' => 3, 'merkle_root' => str_repeat('a', 64),
        'prev_digest' => null, 'digest' => '9610c8b8570e926730acb23681008aef5c9f3b46fe1ec6ea62a2e77a484bfbf4', 'created_at' => '2026-10-01 12:00:00.000000',
    ]);
    DB::table('integrity_anchor_proofs')->insert(['anchor_id' => $id, 'driver' => 'rfc3161', 'proof' => base64_encode($response), 'created_at' => '2026-10-01 12:00:00.000000']);
    config(['model-integrity.anchors.rfc3161' => ['url' => 'https://tsa.example/tsr', 'ca_file' => $fixtures.'/test-ca.pem']]);

    $this->artisan('model-integrity:anchor-export', ['anchor' => $id, 'directory' => $this->directory])
        ->expectsOutputToContain('openssl ts -verify')
        ->assertExitCode(0);

    $exported = $this->directory.'/00000000000000000003-9610c8b8570e926730acb23681008aef5c9f3b46fe1ec6ea62a2e77a484bfbf4.json';

    expect(File::get($exported.'.tsr'))->toBe($response);

    // Where the openssl command is available, it must accept the exported files.
    exec('openssl version 2>&1', $version, $missing);

    if ($missing === 0) {
        exec(sprintf('openssl ts -verify -in %s -data %s -CAfile %s -untrusted %s 2>&1', escapeshellarg($exported.'.tsr'), escapeshellarg($exported), escapeshellarg($fixtures.'/test-ca.pem'), escapeshellarg($fixtures.'/test-tsa.pem')), $output);

        expect(implode("\n", $output))->toContain('Verification: OK');
    }
});
