<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Contract;

beforeEach(function (): void {
    Storage::fake('integrity');
    config(['model-integrity.files.disk' => 'integrity']);

    $path = tempnam(sys_get_temp_dir(), 'mi');
    file_put_contents($path, 'signed contract');
    $this->file = IntegrityFiles::store($path);
});

it('stores the hash in the column and returns the stored file', function (): void {
    $contract = Contract::query()->create(['title' => 'Lease', 'document' => $this->file]);

    expect(DB::table('contracts')->value('document'))->toBe($this->file->sha256)
        ->and($contract->fresh()->document)->toBeInstanceOf(StoredFile::class)
        ->and($contract->fresh()->document->is($this->file))->toBeTrue();
});

it('puts the file hash into the snapshot', function (): void {
    $contract = Contract::query()->create(['title' => 'Lease', 'document' => $this->file]);

    expect($contract->integrityVersions()->sole()->snapshot['document'])->toBe($this->file->sha256);
});

it('accepts a hash string and null', function (): void {
    $contract = Contract::query()->create(['title' => 'Lease', 'document' => $this->file->sha256]);
    $contract->update(['document' => null]);

    expect($contract->fresh()->document)->toBeNull();
});

it('rejects values that are no sha256 hash', function (mixed $value): void {
    Contract::query()->create(['title' => 'Lease', 'document' => $value]);
})->with([
    'short' => ['abc'],
    'uppercase' => [str_repeat('A', 64)],
    'upload path' => ['/tmp/file.pdf'],
])->throws(InvalidArgumentException::class);
