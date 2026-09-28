<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Document;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Tag;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;

beforeEach(function (): void {
    // Set before recording: versions store the morph class at that time.
    Relation::morphMap(['invoice' => Invoice::class]);

    $this->first = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);   // 1
    $this->second = Invoice::query()->create(['number' => 'RE-2', 'total' => '2.00']);  // 2
    $this->document = Document::query()->create(['title' => 'A']);                     // 3
});

it('passes when everything is intact', function (): void {
    // One expectation per output line: Laravel matches each line only once.
    $this->artisan('model-integrity:verify')
        ->expectsOutputToContain('No violations. Checked 3 versions.')
        ->assertExitCode(0);
});

it('fails with a list of violations', function (): void {
    DB::table('integrity_versions')->where('sequence', 3)->update(['snapshot' => '{"title":"X"}']);

    $this->artisan('model-integrity:verify')
        ->expectsOutputToContain('hash_mismatch')
        ->expectsOutputToContain((new Document)->getMorphClass())
        ->expectsOutputToContain('violations found')
        ->assertExitCode(1);
});

it('checks a single type', function (string $model): void {
    DB::table('integrity_versions')->where('sequence', 3)->update(['snapshot' => '{"title":"X"}']);

    $this->artisan('model-integrity:verify', ['--model' => $model])
        ->expectsOutputToContain('No violations')
        ->assertExitCode(0);
})->with([
    'class name' => [Invoice::class],
    'morph alias' => ['invoice'],
]);

it('checks a single model, also after its deletion', function (): void {
    $this->second->delete();
    Invoice::query()->whereKey($this->first->getKey())->update(['total' => '9.00']);

    $this->artisan('model-integrity:verify', ['--model' => Invoice::class, '--id' => $this->second->getKey()])
        ->assertExitCode(0);

    $this->artisan('model-integrity:verify', ['--model' => Invoice::class, '--id' => $this->first->getKey()])
        ->expectsOutputToContain('state_drift')
        ->assertExitCode(1);
});

it('stops after the first failing model with --fail-fast', function (): void {
    Invoice::query()->update(['total' => '9.00']);

    $all = app(IntegrityChecker::class)->checkType(Invoice::class);
    $first = app(IntegrityChecker::class)->checkType(Invoice::class, stopOnFirstFailure: true);

    expect($all->errors())->toHaveCount(2)
        ->and($first->errors())->toHaveCount(1);

    $this->artisan('model-integrity:verify', ['--model' => Invoice::class, '--fail-fast' => true])
        ->expectsOutputToContain('1 violation found')
        ->assertExitCode(1);
});

it('stops after a broken chain with --fail-fast', function (): void {
    DB::table('integrity_versions')->where('sequence', 2)->delete();

    $result = app(IntegrityChecker::class)->checkAll(stopOnFirstFailure: true);

    expect($result->errors()->pluck('type.value')->unique()->all())->toBe(['sequence_gap']);
});

it('rejects an id without a model', function (): void {
    $this->artisan('model-integrity:verify', ['--id' => 1])->assertExitCode(2);
});

it('rejects unknown or untracked models', function (string $model): void {
    $this->artisan('model-integrity:verify', ['--model' => $model])
        ->expectsOutputToContain('HasIntegrity')
        ->assertExitCode(2);
})->with(['App\Models\Missing', Tag::class]);

afterEach(function (): void {
    Relation::morphMap([], false);
});
