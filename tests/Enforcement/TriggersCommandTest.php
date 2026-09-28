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
