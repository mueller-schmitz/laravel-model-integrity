<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Anchoring\Anchorer;
use MuellerSchmitz\ModelIntegrity\Anchoring\MerkleTree;
use MuellerSchmitz\ModelIntegrity\Export\GdpduDtd;
use MuellerSchmitz\ModelIntegrity\Facades\IntegritySubjects;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Customer;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Order;

beforeEach(function (): void {
    Storage::fake('anchors');
    $dtd = tempnam(sys_get_temp_dir(), 'mi-dtd');
    file_put_contents($dtd, '<!-- test dtd -->');
    $this->dtdFile = $dtd;
    app()->instance(GdpduDtd::class, new GdpduDtd(hash('sha256', '<!-- test dtd -->')));
    config([
        'model-integrity.anchors.drivers' => ['disk'],
        'model-integrity.anchors.disk' => ['disk' => 'anchors', 'path' => 'statements'],
        'model-integrity.export.dtd_path' => $dtd,
        'model-integrity.export.supplier' => ['name' => 'Example GmbH', 'location' => 'Example City'],
    ]);

    $invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '119.00']);
    $this->customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    Order::query()->create(['customer_id' => $this->customer->id, 'number' => 'O-1', 'shipping_name' => 'Ada Lovelace', 'total' => '5.00']);
    app(Anchorer::class)->anchor();
    $invoice->update(['total' => '120.00']);
    app(Anchorer::class)->anchor();

    $this->directory = sys_get_temp_dir().'/mi-export-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
    @unlink($this->dtdFile);
});

/**
 * @return list<array<string, string>>
 */
function csvRows(string $file): array
{
    $lines = array_values(array_filter(explode("\r\n", (string) file_get_contents($file)), fn (string $line): bool => $line !== ''));
    $header = str_getcsv(array_shift($lines), ';', '"', '');

    return array_map(fn (string $line): array => array_combine($header, str_getcsv($line, ';', '"', '')), $lines);
}

function export(string $directory, array $options = []): int
{
    return test()->artisan('model-integrity:export', ['directory' => $directory, ...$options])->run();
}

it('writes the complete auditor export', function (): void {
    expect(export($this->directory))->toBe(0);

    foreach (['index.xml', 'gdpdu-01-03-2019.dtd', 'versions.csv', 'versions.jsonl', 'anchors.csv', 'anchor_proofs.csv', 'inclusion_proofs.csv', 'files.csv', 'report.json', 'report.html', 'SPEC.md', 'SHA256SUMS'] as $file) {
        expect(File::exists($this->directory.'/'.$file))->toBeTrue($file);
    }

    expect(File::files($this->directory.'/proofs'))->toHaveCount(2)
        ->and(json_decode((string) File::get($this->directory.'/report.json'), true)['checks']['all']['passes'])->toBeTrue();
});

it('exports every version so that its hash can be recomputed', function (): void {
    export($this->directory);

    $lines = array_filter(explode("\n", (string) File::get($this->directory.'/versions.jsonl')));
    $hasher = app(Hasher::class);

    expect($lines)->toHaveCount(DB::table('integrity_versions')->count());

    foreach ($lines as $line) {
        $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

        expect($hasher->hash($record['envelope']))->toBe($record['hash']);
    }
});

it('proves the inclusion of every exported version in its anchor', function (): void {
    export($this->directory);

    $anchors = collect(csvRows($this->directory.'/anchors.csv'))->keyBy('id');
    $hashes = collect(csvRows($this->directory.'/versions.csv'))->pluck('hash', 'sequence');
    $proofs = csvRows($this->directory.'/inclusion_proofs.csv');

    expect($proofs)->toHaveCount($hashes->count());

    foreach ($proofs as $proof) {
        $path = $proof['audit_path'] === '' ? [] : explode(' ', $proof['audit_path']);

        expect((new MerkleTree)->verifyInclusion($hashes[$proof['sequence']], (int) $proof['leaf_index'], (int) $proof['tree_size'], $path, $anchors[$proof['anchor_id']]['merkle_root']))->toBeTrue();
    }
});

it('lists a checksum for every file', function (): void {
    export($this->directory);

    $sums = collect(explode("\n", trim((string) File::get($this->directory.'/SHA256SUMS'))))
        ->mapWithKeys(function (string $line): array {
            [$hash, $file] = explode('  ', $line, 2);

            return [$file => $hash];
        });
    $files = collect(File::allFiles($this->directory))->map(fn ($file): string => str_replace('\\', '/', $file->getRelativePathname()))->reject(fn (string $file): bool => $file === 'SHA256SUMS')->sort()->values();

    expect($sums->keys()->sort()->values()->all())->toBe($files->all());

    foreach ($sums as $file => $hash) {
        expect(hash_file('sha256', $this->directory.'/'.$file))->toBe($hash);
    }
});

it('exports personal data encrypted and, unless disabled, revealed', function (): void {
    export($this->directory);
    $orders = collect(csvRows($this->directory.'/versions.csv'))->where('versionable_type', (new Order)->getMorphClass());

    expect($orders->first()['snapshot'])->not->toContain('Ada Lovelace')
        ->and($orders->first()['snapshot_revealed'])->toContain('Ada Lovelace');

    File::deleteDirectory($this->directory);
    export($this->directory, ['--no-reveal' => true]);

    expect(File::get($this->directory.'/versions.csv'))->not->toContain('Ada Lovelace')->not->toContain('snapshot_revealed')
        ->and(File::get($this->directory.'/versions.jsonl'))->not->toContain('Ada Lovelace')
        ->and(File::get($this->directory.'/index.xml'))->not->toContain('snapshot_revealed');
});

it('leaves shredded personal data empty in the revealed snapshot', function (): void {
    IntegritySubjects::shred($this->customer);
    DB::table('customers')->update(['name' => 'deleted', 'email' => null]);
    DB::table('orders')->update(['shipping_name' => '']);

    export($this->directory);

    expect(File::get($this->directory.'/versions.csv'))->not->toContain('Ada Lovelace');
});

it('exports a subset of models with proofs that hold on their own', function (): void {
    export($this->directory, ['--model' => Invoice::class]);

    $versions = csvRows($this->directory.'/versions.csv');

    expect(collect($versions)->pluck('versionable_type')->unique()->values()->all())->toBe([(new Invoice)->getMorphClass()])
        ->and(csvRows($this->directory.'/inclusion_proofs.csv'))->toHaveCount(count($versions));
});

it('exports the versions of a period', function (): void {
    DB::table('integrity_versions')->where('sequence', 1)->update(['created_at' => '2025-12-31 23:00:00.000000']);

    export($this->directory, ['--from' => '2026-01-01']);

    expect(collect(csvRows($this->directory.'/versions.csv'))->pluck('sequence')->all())->not->toContain('1');
});

it('reports violations and fails, but still writes the export', function (): void {
    DB::table('integrity_versions')->where('sequence', 1)->update(['reason' => 'tampered']);

    expect(export($this->directory))->toBe(1)
        ->and(json_decode((string) File::get($this->directory.'/report.json'), true)['checks']['all']['passes'])->toBeFalse()
        ->and(File::get($this->directory.'/report.html'))->toContain('hash_mismatch');
});

it('refuses to write into a directory that is not empty', function (): void {
    File::ensureDirectoryExists($this->directory);
    File::put($this->directory.'/other.txt', 'x');

    expect(export($this->directory))->toBe(2);
});

it('needs the DTD unless told to go without', function (): void {
    config(['model-integrity.export.dtd_path' => null]);

    expect(export($this->directory))->toBe(2)
        ->and(export($this->directory, ['--without-dtd' => true]))->toBe(0)
        ->and(File::exists($this->directory.'/gdpdu-01-03-2019.dtd'))->toBeFalse();
});

it('writes an index.xml that is valid against the published DTD', function (): void {
    app()->forgetInstance(GdpduDtd::class);
    config(['model-integrity.export.dtd_path' => getenv('MI_GDPDU_DTD')]);

    expect(export($this->directory))->toBe(0);

    $document = new DOMDocument;
    $document->load($this->directory.'/index.xml', LIBXML_DTDLOAD);
    libxml_use_internal_errors(true);
    $valid = $document->validate();
    // The published DTD itself is flagged as not deterministic by libxml; that is no error of the file.
    $errors = array_values(array_filter(array_map(fn (LibXMLError $error): string => trim($error->message), libxml_get_errors()), fn (string $message): bool => ! str_contains($message, 'is not determinist')));
    libxml_clear_errors();

    expect($valid)->toBeTrue()->and($errors)->toBe([]);
})->skip(fn () => getenv('MI_GDPDU_DTD') === false, 'Set MI_GDPDU_DTD to the path of gdpdu-01-03-2019.dtd to validate against it.');
