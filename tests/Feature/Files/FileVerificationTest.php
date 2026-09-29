<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Contract;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

beforeEach(function (): void {
    Storage::fake('integrity');
    config(['model-integrity.files.disk' => 'integrity']);

    $this->checker = app(IntegrityChecker::class);
    $this->first = IntegrityFiles::store(fileWithContents('first draft'));
    $this->second = IntegrityFiles::store(fileWithContents('signed version'));

    $this->contract = Contract::query()->create(['title' => 'Lease', 'document' => $this->first]);
    $this->contract->update(['document' => $this->second]);
});

function fileWithContents(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'mi');
    file_put_contents($path, $contents);

    return $path;
}

/**
 * @return list<string>
 */
function fileErrors(IntegrityResult $result): array
{
    return $result->errors()
        ->filter(fn (IntegrityError $error): bool => $error->type->value === 'file_mismatch')
        ->map(fn (IntegrityError $error): string => $error->message)
        ->values()
        ->all();
}

it('passes when all referenced files are intact', function (): void {
    expect($this->checker->checkModel($this->contract)->passes())->toBeTrue()
        ->and($this->checker->checkFiles()->passes())->toBeTrue()
        ->and($this->checker->checkFiles()->checkedVersions())->toBe(2)
        ->and($this->checker->checkAll()->passes())->toBeTrue();
});

it('detects a referenced file missing on the disk, also in older versions', function (): void {
    Storage::disk('integrity')->delete($this->first->path);

    $errors = fileErrors($this->checker->checkModel($this->contract));

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain($this->first->sha256)->toContain('missing')->toContain('document');
});

it('detects a referenced file with a different size', function (): void {
    Storage::disk('integrity')->put($this->second->path, 'changed');

    expect(fileErrors($this->checker->checkModel($this->contract)))->toHaveCount(1);
});

it('detects changed content of the same size only when hashing contents', function (): void {
    Storage::disk('integrity')->put($this->second->path, str_repeat('x', strlen('signed version')));

    expect(fileErrors($this->checker->checkModel($this->contract)))->toBe([])
        ->and(fileErrors($this->checker->checkFiles()))->toHaveCount(1)
        ->and(fileErrors($this->checker->checkFiles())[0])->toContain('content');
});

it('detects a referenced hash without a stored file record', function (): void {
    DB::table('integrity_files')->where('sha256', $this->second->sha256)->delete();

    $errors = fileErrors($this->checker->checkModel($this->contract));

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('no stored file');
});

it('detects a tampered stored file record through its own chain', function (): void {
    DB::table('integrity_files')->where('id', $this->second->id)->update(['size' => 1]);

    $types = $this->checker->checkModel($this->second->fresh())->errors()->map(fn (IntegrityError $e): string => $e->type->value)->all();

    expect($types)->toContain('state_drift');
});

it('reports a stored file missing on the disk even if no model references it', function (): void {
    $orphan = IntegrityFiles::store(fileWithContents('unreferenced'));
    Storage::disk('integrity')->delete($orphan->path);

    expect(fileErrors($this->checker->checkFiles(contents: false)))->toHaveCount(1);
});

it('reports files of hard-deleted models', function (): void {
    $invoiceFile = IntegrityFiles::store(fileWithContents('deleted contract'));
    $contract = Contract::query()->create(['title' => 'Gone', 'document' => $invoiceFile]);
    $contract->forceDelete();
    Storage::disk('integrity')->delete($invoiceFile->path);

    expect(fileErrors($this->checker->checkModel($contract)))->toHaveCount(1);
});

it('reports a disk that is no longer configured instead of crashing', function (): void {
    DB::table('integrity_files')->where('id', $this->second->id)->update(['disk' => 'removed-disk']);

    $errors = fileErrors($this->checker->checkModel($this->contract));

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('cannot be checked');
});

describe('verify command', function (): void {
    it('hashes file contents with --files', function (): void {
        Storage::disk('integrity')->put($this->second->path, str_repeat('x', strlen('signed version')));

        $this->artisan('model-integrity:verify')->assertExitCode(0);

        $this->artisan('model-integrity:verify', ['--files' => true])
            ->expectsOutputToContain('file_mismatch')
            ->assertExitCode(1);
    });
});

it('lists the stored file class among checked types', function (): void {
    expect($this->checker->checkType(StoredFile::class)->checkedVersions())->toBe(2);
});
