<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;

/*
 * DDL commits the test transaction on MySQL and MariaDB, so these run on
 * PostgreSQL and SQLite only; the trigger SQL itself is covered for every
 * engine by AppendOnlyTriggersTest.
 */

beforeEach(function (): void {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('DDL commits the test transaction on MySQL/MariaDB');
    }

    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
});

it('removes and installs the triggers', function (): void {
    $this->artisan('model-integrity:triggers', ['--remove' => true])
        ->expectsOutputToContain('removed')
        ->assertExitCode(0);

    DB::table('integrity_versions')->where('sequence', 1)->update(['reason' => 'x']);

    $this->artisan('model-integrity:triggers')
        ->expectsOutputToContain('installed')
        ->assertExitCode(0);

    expect(fn () => DB::table('integrity_versions')->where('sequence', 1)->update(['reason' => 'y']))
        ->toThrow(QueryException::class, 'append-only');
});

it('covers the stored files table as well', function (): void {
    $this->artisan('model-integrity:triggers', ['--remove' => true])->assertExitCode(0);
    DB::table('integrity_files')->insert(['sha256' => str_repeat('a', 64), 'disk' => 'local', 'path' => 'x', 'size' => 1, 'created_at' => '2026-09-29 10:00:00']);
    DB::table('integrity_files')->update(['size' => 2]);

    $this->artisan('model-integrity:triggers')
        ->expectsOutputToContain('integrity_files')
        ->assertExitCode(0);

    expect(fn () => DB::table('integrity_files')->update(['size' => 3]))->toThrow(QueryException::class, 'append-only');
});

it('covers the anchor tables as well', function (): void {
    $this->artisan('model-integrity:triggers', ['--remove' => true])
        ->expectsOutputToContain('integrity_anchor_proofs')
        ->assertExitCode(0);
    DB::table('integrity_anchors')->insert(['anchor_format' => 1, 'from_sequence' => 1, 'to_sequence' => 1, 'merkle_root' => str_repeat('a', 64), 'digest' => str_repeat('b', 64), 'created_at' => '2026-10-01 10:00:00']);
    DB::table('integrity_anchors')->update(['to_sequence' => 2]);

    $this->artisan('model-integrity:triggers')
        ->expectsOutputToContain('integrity_anchors')
        ->assertExitCode(0);

    expect(fn () => DB::table('integrity_anchors')->update(['to_sequence' => 3]))->toThrow(QueryException::class, 'append-only');
});

it('fails when no integrity table exists yet', function (): void {
    config([
        'model-integrity.tables.versions' => 'missing_versions',
        'model-integrity.tables.files' => 'missing_files',
        'model-integrity.tables.anchors' => 'missing_anchors',
        'model-integrity.tables.anchor_proofs' => 'missing_anchor_proofs',
    ]);

    $this->artisan('model-integrity:triggers')
        ->expectsOutputToContain('migrate')
        ->assertExitCode(1);
});

it('installs the triggers twice without failing', function (): void {
    $this->artisan('model-integrity:triggers')->assertExitCode(0);
    $this->artisan('model-integrity:triggers')->assertExitCode(0);

    expect(fn () => DB::table('integrity_versions')->delete())->toThrow(QueryException::class, 'append-only');
});

it('accepts string config values from the environment', function (): void {
    config(['model-integrity.append_only_triggers' => 'false']);
    $migration = require __DIR__.'/../../database/migrations/2026_09_27_000003_create_integrity_append_only_triggers.php';

    $migration->down();
    $migration->up();

    // "false" disables the triggers instead of crashing the migration.
    expect(DB::table('integrity_versions')->where('sequence', 1)->update(['reason' => 'x']))->toBe(1);
});
