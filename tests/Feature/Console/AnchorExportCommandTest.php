<?php

declare(strict_types=1);

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
