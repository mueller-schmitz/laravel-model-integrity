<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Contract;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Customer;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;

class ContractWithLateDocument extends Contract
{
    protected $table = 'contracts';

    /** @var list<string> */
    protected array $integrityOmitNull = ['document'];
}

class CustomerWithLateEmail extends Customer
{
    protected $table = 'customers';

    /** @var list<string> */
    protected array $integrityOmitNull = ['email'];
}

beforeEach(function (): void {
    Storage::fake('integrity');
    config(['model-integrity.files.disk' => 'integrity']);

    $path = tempnam(sys_get_temp_dir(), 'mi');
    file_put_contents($path, 'signed');
    $this->file = IntegrityFiles::store($path);
    @unlink($path);
});

it('leaves an attribute out of the snapshot while it is null', function (): void {
    $contract = ContractWithLateDocument::query()->create(['title' => 'Lease']);

    expect($contract->integrityVersions()->sole()->snapshot)->not->toHaveKey('document')->toHaveKey('title')
        ->and($contract->verifyIntegrity()->passes())->toBeTrue();
});

it('records the attribute once it holds a value, and leaves it out again when it is null', function (): void {
    $contract = ContractWithLateDocument::query()->create(['title' => 'Lease']);
    $contract->update(['document' => $this->file]);
    $contract->update(['document' => null]);

    [$first, $second, $third] = $contract->integrityVersions()->get()->all();

    expect($first->snapshot)->not->toHaveKey('document')
        ->and($second->snapshot['document'])->toBe($this->file->sha256)
        ->and($third->snapshot)->not->toHaveKey('document')
        ->and($contract->verifyIntegrity()->passes())->toBeTrue();
});

it('detects a value that was set or removed without a version', function (?string $recorded, ?string $manipulated): void {
    $contract = ContractWithLateDocument::query()->create(['title' => 'Lease', 'document' => $recorded]);

    DB::table('contracts')->where('id', $contract->id)->update(['document' => $manipulated]);

    $drift = $contract->verifyIntegrity()->errors()
        ->filter(fn (IntegrityError $error): bool => $error->type->value === 'state_drift')
        ->map(fn (IntegrityError $error): string => $error->message)
        ->values();

    expect($drift)->toHaveCount(1)
        ->and($drift[0])->toContain('[document]');
})->with([
    'set' => [null, hash('sha256', 'signed')],
    'removed' => [hash('sha256', 'signed'), null],
]);

it('rejects personal attributes that would be left out while they are null', function (): void {
    CustomerWithLateEmail::query()->create(['number' => 'C-1', 'name' => 'Ada Lovelace']);
})->throws(IntegrityConfigurationException::class, 'remove them from $integrityOmitNull');
