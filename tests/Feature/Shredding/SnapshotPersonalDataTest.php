<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Exceptions\ShreddedSubjectException;
use MuellerSchmitz\ModelIntegrity\Facades\IntegritySubjects;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Customer;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;

/*
 * model-integrity:snapshot must record personal attributes encrypted, like
 * every other version: a plain snapshot could never be shredded.
 */

beforeEach(function (): void {
    $this->customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
});

/**
 * @return list<string>
 */
function plainPersonalSnapshots(): array
{
    return DB::table('integrity_versions')->pluck('snapshot')
        ->filter(fn (mixed $snapshot): bool => str_contains((string) $snapshot, 'Ada Lovelace') || str_contains((string) $snapshot, 'ada@example.com'))
        ->values()
        ->all();
}

it('records no snapshot for a model whose personal attributes are unchanged', function (array $options): void {
    $this->artisan('model-integrity:snapshot', $options)->expectsOutputToContain('0 snapshots recorded')->assertSuccessful();

    expect($this->customer->integrityVersions()->count())->toBe(1)
        ->and(plainPersonalSnapshots())->toBe([]);
})->with([
    'all' => [['--all' => true]],
    'model' => [['--model' => Customer::class]],
]);

it('encrypts personal attributes in the snapshots it records', function (): void {
    // Changed without a version, as after a schema change.
    DB::table('customers')->where('id', $this->customer->id)->update(['number' => 'C-2']);

    $this->artisan('model-integrity:snapshot', ['--model' => Customer::class, '--reason' => 'Renumbered'])
        ->expectsOutputToContain('1 snapshot recorded')
        ->assertSuccessful();

    $version = $this->customer->integrityVersions()->get()->last();

    expect($version->event)->toBe('snapshot')
        ->and($version->reason)->toBe('Renumbered')
        ->and($version->snapshot['number'])->toBe('C-2')
        ->and($version->snapshot['name'])->toHaveKey('@encrypted')
        ->and($version->revealedSnapshot()->snapshot['name'])->toBe('Ada Lovelace')
        ->and(plainPersonalSnapshots())->toBe([])
        ->and(app(IntegrityChecker::class)->checkAll()->errors()->map(fn (IntegrityError $error): string => (string) $error)->all())->toBe([]);
});

it('encrypts personal attributes when it records the first version of a row', function (): void {
    $id = DB::table('customers')->insertGetId(['number' => 'C-9', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com', 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('model-integrity:snapshot', ['--model' => Customer::class])->assertSuccessful();

    $version = Customer::query()->findOrFail($id)->integrityVersions()->sole();

    expect($version->event)->toBe('created')
        ->and($version->snapshot['name'])->toHaveKey('@encrypted')
        ->and(plainPersonalSnapshots())->toBe([]);
});

it('records nothing for a shredded subject whose other attributes are unchanged', function (array $row): void {
    IntegritySubjects::shred($this->customer);
    DB::table('customers')->where('id', $this->customer->id)->update($row);

    // Like the state drift check: shredded values are gone and not compared.
    $this->artisan('model-integrity:snapshot', ['--model' => Customer::class])->expectsOutputToContain('0 snapshots recorded')->assertSuccessful();

    expect($this->customer->integrityVersions()->count())->toBe(1);
})->with([
    'anonymized by SQL' => [['name' => 'deleted', 'email' => null]],
    'not anonymized yet' => [['number' => 'C-1']],
]);

it('records the anonymized values of a shredded subject with a new baseline', function (): void {
    IntegritySubjects::shred($this->customer);
    DB::table('customers')->where('id', $this->customer->id)->update(['number' => 'C-2', 'name' => 'deleted', 'email' => null]);

    $this->artisan('model-integrity:snapshot', ['--model' => Customer::class])->expectsOutputToContain('1 snapshot recorded')->assertSuccessful();
    $this->artisan('model-integrity:snapshot', ['--model' => Customer::class])->expectsOutputToContain('0 snapshots recorded')->assertSuccessful();

    expect($this->customer->integrityVersions()->get()->last()->snapshot)->toMatchArray(['number' => 'C-2', 'name' => 'deleted', 'email' => null])
        ->and(app(IntegrityChecker::class)->checkAll()->errors()->map(fn (IntegrityError $error): string => (string) $error)->all())->toBe([]);
});

it('refuses to record personal data left in the row of a shredded subject', function (): void {
    IntegritySubjects::shred($this->customer);
    DB::table('customers')->where('id', $this->customer->id)->update(['number' => 'C-2']);

    try {
        $this->artisan('model-integrity:snapshot', ['--model' => Customer::class])->run();
    } finally {
        expect($this->customer->integrityVersions()->count())->toBe(1)
            ->and(plainPersonalSnapshots())->toBe([]);
    }
})->throws(ShreddedSubjectException::class, 'must be null or their anonymized value');
