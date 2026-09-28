<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Database\AppendOnlyTriggers;

/*
 * Applies the output of model-integrity:grants to a fresh database user and
 * connects as that user. The triggers are removed first, so the rejections
 * below come from the privileges alone.
 *
 * Admin statements run on a separate connection: they must be committed
 * (and must not commit the test transaction on MySQL).
 */

const RESTRICTED_USER = 'mi_restricted';
const RESTRICTED_PASSWORD = 'mi-Restricted-1';

function connectionAs(string $name, array $overrides = []): Connection
{
    config(['database.connections.'.$name => array_merge(config('database.connections.'.config('database.default')), $overrides)]);

    /** @var Connection */
    return DB::connection($name);
}

function dropRestrictedUser(Connection $admin): void
{
    if ($admin->getDriverName() === 'pgsql') {
        if ($admin->selectOne('SELECT 1 AS found FROM pg_roles WHERE rolname = ?', [RESTRICTED_USER]) !== null) {
            $admin->unprepared('DROP OWNED BY "'.RESTRICTED_USER.'"; DROP ROLE "'.RESTRICTED_USER.'"');
        }

        return;
    }

    $admin->unprepared("DROP USER IF EXISTS '".RESTRICTED_USER."'@'%'");
}

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite has no users or privileges.');
    }

    $this->admin = connectionAs('mi_admin');

    dropRestrictedUser($this->admin);
    app(AppendOnlyTriggers::class)->uninstall($this->admin, 'integrity_versions');

    $this->admin->unprepared($this->admin->getDriverName() === 'pgsql'
        ? 'CREATE ROLE "'.RESTRICTED_USER."\" LOGIN PASSWORD '".RESTRICTED_PASSWORD."'"
        : "CREATE USER '".RESTRICTED_USER."'@'%' IDENTIFIED BY '".RESTRICTED_PASSWORD."'");

    Artisan::call('model-integrity:grants', ['--user' => RESTRICTED_USER]);

    collect(explode("\n", Artisan::output()))
        ->map(fn (string $line): string => trim($line))
        ->filter(fn (string $line): bool => $line !== '' && ! str_starts_with($line, '--'))
        ->each(fn (string $statement) => $this->admin->unprepared($statement));

    $this->restricted = connectionAs('mi_restricted', ['username' => RESTRICTED_USER, 'password' => RESTRICTED_PASSWORD]);
});

afterEach(function (): void {
    if (! isset($this->admin)) {
        return;
    }

    DB::purge('mi_restricted');
    dropRestrictedUser($this->admin);
    DB::purge('mi_admin');
});

function versionRow(int $sequence): array
{
    return [
        'sequence' => $sequence,
        'versionable_type' => 'App\Models\Invoice',
        'versionable_id' => '1',
        'version' => $sequence,
        'event' => 'created',
        'hash_format' => 1,
        'schema_version' => 1,
        'snapshot' => '{}',
        'hash' => str_repeat((string) $sequence, 64),
        'created_at' => '2026-09-28 10:00:00.000000',
    ];
}

it('allows reading and appending versions', function (): void {
    $this->restricted->table('integrity_versions')->insert(versionRow(1));

    expect($this->restricted->table('integrity_versions')->count())->toBe(1);
});

it('denies updating and deleting versions', function (string $operation): void {
    $this->restricted->table('integrity_versions')->insert(versionRow(1));

    $query = $this->restricted->table('integrity_versions')->where('sequence', 1);

    expect(fn () => $operation === 'update' ? $query->update(['event' => 'updated']) : $query->delete())
        ->toThrow(QueryException::class, $this->restricted->getDriverName() === 'pgsql' ? 'permission denied' : 'denied');
})->with(['update', 'delete']);

it('allows advancing the chain head but not deleting it', function (): void {
    $this->restricted->table('integrity_heads')->where('chain', 'global')->update(['sequence' => 1]);

    expect(fn () => $this->restricted->table('integrity_heads')->where('chain', 'global')->delete())
        ->toThrow(QueryException::class, 'denied');
});
