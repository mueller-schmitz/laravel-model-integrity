<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('prints the statements for the connection', function (): void {
    $exitCode = Artisan::call('model-integrity:grants', ['--user' => 'app']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);

    match (DB::connection()->getDriverName()) {
        'sqlite' => expect($output)->toContain('no users'),
        'pgsql' => expect($output)->toContain('GRANT SELECT, INSERT ON TABLE "integrity_versions" TO "app";'),
        default => expect($output)->toContain('GRANT SELECT, INSERT ON `'.DB::connection()->getDatabaseName().'`.`integrity_versions` TO \'app\'@\'%\';'),
    };
});

it('uses the database user of the connection by default', function (): void {
    config(['database.connections.'.config('database.default').'.username' => 'configured_user']);
    DB::purge();

    Artisan::call('model-integrity:grants');

    expect(Artisan::output())->toContain('configured_user');
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no users');

it('lists all other tables with --all-tables on MySQL', function (): void {
    Artisan::call('model-integrity:grants', ['--user' => 'app', '--all-tables' => true]);

    expect(Artisan::output())
        ->toContain('REVOKE ALL PRIVILEGES')
        ->toContain('`invoices`')
        ->not->toContain('SELECT, INSERT, UPDATE, DELETE ON `'.DB::connection()->getDatabaseName().'`.`integrity_versions`');
})->skip(fn () => ! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'MySQL and MariaDB only');
