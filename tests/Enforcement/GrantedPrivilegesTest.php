<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Database\AppendOnlyTriggers;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Contract;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Post;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Tag;
use MuellerSchmitz\ModelIntegrity\Tests\Support\OpenTimestampsFake;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;

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
    foreach (['integrity_files', 'integrity_anchors', 'integrity_anchor_proofs'] as $table) {
        app(AppendOnlyTriggers::class)->uninstall($this->admin, $table);
    }

    $this->admin->unprepared($this->admin->getDriverName() === 'pgsql'
        ? 'CREATE ROLE "'.RESTRICTED_USER."\" LOGIN PASSWORD '".RESTRICTED_PASSWORD."'"
        : "CREATE USER '".RESTRICTED_USER."'@'%' IDENTIFIED BY '".RESTRICTED_PASSWORD."'");

    Artisan::call('model-integrity:grants', ['--user' => RESTRICTED_USER]);

    collect(explode("\n", Artisan::output()))
        ->map(fn (string $line): string => trim($line))
        ->filter(fn (string $line): bool => $line !== '' && ! str_starts_with($line, '--'))
        ->each(fn (string $statement) => $this->admin->unprepared($statement));

    // The application's own tables, as any application user has them.
    foreach (['invoices', 'posts', 'tags', 'post_tag', 'contracts'] as $table) {
        $this->admin->unprepared($this->admin->getDriverName() === 'pgsql'
            ? "GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE \"{$table}\" TO \"".RESTRICTED_USER.'"'
            : "GRANT SELECT, INSERT, UPDATE, DELETE ON `{$this->admin->getDatabaseName()}`.`{$table}` TO '".RESTRICTED_USER."'@'%'");

        if ($this->admin->getDriverName() === 'pgsql' && $table !== 'post_tag') {
            $this->admin->unprepared("GRANT USAGE ON SEQUENCE \"{$table}_id_seq\" TO \"".RESTRICTED_USER.'"');
        }
    }

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

it('lets the application record, delete and verify through the package API', function (): void {
    // Models and integrity tables on the restricted connection, like a real application.
    config(['model-integrity.connection' => 'mi_restricted']);

    $invoice = Invoice::on('mi_restricted')->create(['number' => 'RE-1', 'total' => '1.00']);
    $invoice->update(['total' => '2.00']);

    $post = Post::on('mi_restricted')->create(['title' => 'Hello']);
    $tag = Tag::on('mi_restricted')->create(['name' => 'a']);
    $this->restricted->transaction(function () use ($post, $tag): void {
        $post->tags()->attach($tag);
        $post->recordRelation('tags');
    });

    $invoice->delete();

    expect($this->restricted->table('integrity_versions')->count())->toBe(5)
        ->and($this->restricted->table('integrity_heads')->where('chain', 'like', 'model%')->count())->toBe(2)
        ->and(app(IntegrityChecker::class)->checkAll()->errors()->map(fn ($e) => (string) $e)->all())->toBe([]);
});

it('lets the application store and reference files', function (): void {
    config(['model-integrity.connection' => 'mi_restricted']);
    Storage::fake('integrity');
    config(['model-integrity.files.disk' => 'integrity']);
    $path = tempnam(sys_get_temp_dir(), 'mi');
    file_put_contents($path, 'restricted upload');

    $file = IntegrityFiles::store($path);
    Contract::on('mi_restricted')->create(['title' => 'Lease', 'document' => $file]);

    expect($this->restricted->table('integrity_files')->count())->toBe(1)
        ->and(app(IntegrityChecker::class)->checkAll()->errors()->map(fn ($e) => (string) $e)->all())->toBe([])
        ->and(fn () => $this->restricted->table('integrity_files')->update(['size' => 1]))
        ->toThrow(QueryException::class, $this->restricted->getDriverName() === 'pgsql' ? 'permission denied' : 'denied');
});

it('lets the application anchor and verify the anchors', function (string $table): void {
    config([
        'model-integrity.connection' => 'mi_restricted',
        'model-integrity.anchors.drivers' => ['disk'],
        'model-integrity.anchors.disk' => ['disk' => 'anchors', 'path' => 'statements'],
    ]);
    Storage::fake('anchors');

    Invoice::on('mi_restricted')->create(['number' => 'RE-1', 'total' => '1.00']);
    Artisan::call('model-integrity:anchor');
    Invoice::on('mi_restricted')->create(['number' => 'RE-2', 'total' => '2.00']);

    expect(Artisan::call('model-integrity:anchor'))->toBe(0)
        ->and($this->restricted->table('integrity_anchors')->count())->toBe(2)
        ->and(app(IntegrityChecker::class)->checkAll()->errors()->map(fn ($e) => (string) $e)->all())->toBe([])
        ->and(fn () => $this->restricted->table($table)->update(['created_at' => '2026-10-02 00:00:00']))
        ->toThrow(QueryException::class, $this->restricted->getDriverName() === 'pgsql' ? 'permission denied' : 'denied');
})->with(['integrity_anchors', 'integrity_anchor_proofs']);

it('lets the application upgrade OpenTimestamps proofs', function (): void {
    config(['model-integrity.connection' => 'mi_restricted']);
    OpenTimestampsFake::install();
    config(['model-integrity.anchors.drivers' => ['opentimestamps']]);

    Invoice::on('mi_restricted')->create(['number' => 'RE-1', 'total' => '1.00']);
    Artisan::call('model-integrity:anchor');
    $digest = (string) $this->restricted->table('integrity_anchors')->value('digest');
    OpenTimestampsFake::confirm(OpenTimestampsFake::ALICE, (string) hex2bin($digest), 900000, now('UTC')->toDateTimeString());

    expect(Artisan::call('model-integrity:anchor-upgrade'))->toBe(0)
        ->and($this->restricted->table('integrity_anchor_proofs')->count())->toBe(2)
        ->and(app(IntegrityChecker::class)->checkAll()->errors()->map(fn ($e) => (string) $e)->all())->toBe([]);
});

it('allows the shared lock the file store uses to find committed records on MySQL and MariaDB', function (): void {
    $this->restricted->transaction(function (): void {
        expect($this->restricted->table('integrity_files')->where('sha256', str_repeat('a', 64))->sharedLock()->first())->toBeNull();
    });
})->skip(fn () => DB::connection()->getDriverName() === 'pgsql', 'PostgreSQL reads committed rows without a lock');

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
