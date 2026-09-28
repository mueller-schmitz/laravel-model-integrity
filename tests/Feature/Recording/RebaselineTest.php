<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Document;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;

/*
 * After a schema change every stored row differs from its last snapshot.
 * Recording a new snapshot per model brings the history up to date.
 */

function addColumnToInvoices(): void
{
    Schema::table('invoices', function ($table): void {
        $table->string('reference')->nullable();
    });
}

it('records a snapshot version with a custom event and reason', function (): void {
    $invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);

    $version = $invoice->recordIntegritySnapshot('schema_migrated', 'Added reference column');

    expect($version->event)->toBe('schema_migrated')
        ->and($version->reason)->toBe('Added reference column')
        ->and($version->version)->toBe(2)
        ->and($invoice->verifyIntegrity()->passes())->toBeTrue();
});

it('resolves state drift after a schema change', function (): void {
    $invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
    addColumnToInvoices();

    expect(app(IntegrityChecker::class)->checkModel($invoice)->errors()->sole()->type->value)->toBe('state_drift');

    $invoice->recordIntegritySnapshot();

    expect(Version::query()->latest('sequence')->first()->event)->toBe('snapshot')
        ->and(app(IntegrityChecker::class)->checkModel($invoice)->passes())->toBeTrue();
});

it('allows snapshots on immutable models', function (): void {
    $document = Document::query()->create(['title' => 'A']);

    expect($document->recordIntegritySnapshot()->version)->toBe(2);
});

it('rejects reserved event names', function (): void {
    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00'])->recordIntegritySnapshot('updated');
})->throws(InvalidArgumentException::class, 'updated');

describe('snapshot command', function (): void {
    it('records snapshots for models whose last snapshot is outdated', function (): void {
        $first = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
        $second = Invoice::query()->create(['number' => 'RE-2', 'total' => '2.00']);
        $current = Invoice::query()->create(['number' => 'RE-3', 'total' => '3.00']);
        addColumnToInvoices();

        // The third invoice already has a snapshot of the new schema.
        $current->recordIntegritySnapshot();

        $this->artisan('model-integrity:snapshot', ['--model' => Invoice::class])
            ->expectsOutputToContain('2 snapshots recorded')
            ->assertExitCode(0);

        expect($first->integrityVersions()->count())->toBe(2)
            ->and($second->integrityVersions()->count())->toBe(2)
            ->and($current->integrityVersions()->count())->toBe(2)
            ->and(app(IntegrityChecker::class)->checkType(Invoice::class)->passes())->toBeTrue();
    });

    it('skips models that are up to date', function (): void {
        Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);

        $this->artisan('model-integrity:snapshot', ['--model' => Invoice::class])
            ->expectsOutputToContain('0 snapshots recorded')
            ->assertExitCode(0);

        expect(Version::query()->count())->toBe(1);
    });

    it('records unrecorded rows as created', function (): void {
        Invoice::withoutEvents(fn () => Invoice::query()->create(['number' => 'RE-X', 'total' => '1.00']));

        $this->artisan('model-integrity:snapshot', ['--model' => Invoice::class, '--reason' => 'Backfill'])
            ->expectsOutputToContain('1 snapshot recorded')
            ->assertExitCode(0);

        $version = Version::query()->sole();

        expect($version->event)->toBe('created')
            ->and($version->reason)->toBe('Backfill');
    });

    it('records every recorded type with --all', function (): void {
        Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
        Document::query()->create(['title' => 'A']);
        addColumnToInvoices();
        DB::table('integrity_versions')->where('versionable_type', (new Document)->getMorphClass())->update(['schema_version' => 0]);

        $this->artisan('model-integrity:snapshot', ['--all' => true])
            ->expectsOutputToContain('2 snapshots recorded')
            ->assertExitCode(0);
    });

    it('requires --model or --all', function (): void {
        $this->artisan('model-integrity:snapshot')->assertExitCode(2);
    });
});
