<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MuellerSchmitz\ModelIntegrity\Export\GdpduDtd;

beforeEach(function (): void {
    $this->contents = "<!-- test dtd -->\n<!ELEMENT DataSet ANY>\n";
    $this->dtd = new GdpduDtd(hash('sha256', $this->contents));
    $this->file = tempnam(sys_get_temp_dir(), 'mi-dtd');
});

afterEach(fn () => @unlink($this->file));

it('reads the DTD from the configured path', function (): void {
    file_put_contents($this->file, $this->contents);
    config(['model-integrity.export.dtd_path' => $this->file]);

    expect($this->dtd->contents(fetch: false))->toBe($this->contents);
});

it('rejects a DTD that is not the published one', function (): void {
    file_put_contents($this->file, $this->contents.'changed');
    config(['model-integrity.export.dtd_path' => $this->file]);

    $this->dtd->contents(fetch: false);
})->throws(RuntimeException::class, 'SHA-256');

it('fails with directions when no DTD is available', function (): void {
    config(['model-integrity.export.dtd_path' => null]);

    $this->dtd->contents(fetch: false);
})->throws(RuntimeException::class, '--fetch-dtd');

it('downloads the DTD from the publisher and checks it', function (): void {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ext-zip is not available.');
    }

    $zip = tempnam(sys_get_temp_dir(), 'mi-zip');
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::OVERWRITE);
    $archive->addFromString('gdpdu-01-03-2019/gdpdu-01-03-2019.dtd', $this->contents);
    $archive->close();
    config(['model-integrity.export.dtd_path' => null, 'model-integrity.export.dtd_url' => 'https://publisher.example/gdpdu.zip']);
    Http::fake(['publisher.example/*' => Http::response((string) file_get_contents($zip))]);
    unlink($zip);

    expect($this->dtd->contents(fetch: true))->toBe($this->contents);
});

it('knows the hash of the published DTD', function (): void {
    expect(GdpduDtd::SHA256)->toBe('691051c9828ec2bbef71527c4aa77554c09ecd49e862fc6f2bc4b14507c72a0e');

    $real = getenv('MI_GDPDU_DTD');

    if ($real !== false) {
        expect(hash_file('sha256', $real))->toBe(GdpduDtd::SHA256);
    }
});
