<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Database\AppendOnlyTriggers;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;

beforeEach(function (): void {
    $this->invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
    $this->invoice->update(['total' => '2.00']);
});

it('rejects updates of versions', function (): void {
    DB::table('integrity_versions')->where('sequence', 1)->update(['snapshot' => '{"total":"9.00"}']);
})->throws(QueryException::class, 'append-only');

it('rejects deletes of versions', function (): void {
    DB::table('integrity_versions')->where('sequence', 2)->delete();
})->throws(QueryException::class, 'append-only');

it('rejects deleting all versions', function (): void {
    DB::table('integrity_versions')->delete();
})->throws(QueryException::class, 'append-only');

it('rejects truncating versions on PostgreSQL', function (): void {
    DB::table('integrity_versions')->truncate();
})->throws(QueryException::class, 'append-only')
    ->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'TRUNCATE fires triggers only on PostgreSQL; elsewhere it is prevented by privileges');

// One statement per test: after an error PostgreSQL rejects every further
// statement of the (test) transaction.
it('rejects updates and deletes of stored file records', function (string $operation): void {
    Storage::fake('integrity');
    config(['model-integrity.files.disk' => 'integrity']);
    $path = tempnam(sys_get_temp_dir(), 'mi');
    file_put_contents($path, 'protected');
    IntegrityFiles::store($path);

    $operation === 'update'
        ? DB::table('integrity_files')->update(['size' => 1])
        : DB::table('integrity_files')->delete();
})->with(['update', 'delete'])->throws(QueryException::class, 'append-only');

it('rejects updates and deletes of anchors and their proofs', function (string $table, string $operation): void {
    $anchorId = DB::table('integrity_anchors')->insertGetId([
        'anchor_format' => 1, 'from_sequence' => 1, 'to_sequence' => 1,
        'merkle_root' => str_repeat('a', 64), 'prev_digest' => null, 'digest' => str_repeat('b', 64),
        'created_at' => '2026-10-01 10:00:00.000000',
    ]);
    DB::table('integrity_anchor_proofs')->insert(['anchor_id' => $anchorId, 'driver' => 'disk', 'proof' => 'x', 'created_at' => '2026-10-01 10:00:00.000000']);

    $operation === 'update'
        ? DB::table($table)->update(['created_at' => '2026-10-02 10:00:00.000000'])
        : DB::table($table)->delete();
})->with(['integrity_anchors', 'integrity_anchor_proofs'])->with(['update', 'delete'])
    ->throws(QueryException::class, 'append-only');

it('keeps recording and verification working', function (): void {
    $this->invoice->update(['total' => '3.00']);
    $this->invoice->delete();

    expect(Version::query()->count())->toBe(4)
        ->and($this->invoice->verifyIntegrity()->passes())->toBeTrue()
        ->and(DB::table('integrity_heads')->value('sequence'))->toEqual(4);
});

it('can be removed and installed again', function (): void {
    $triggers = app(AppendOnlyTriggers::class);
    $connection = DB::connection();

    $triggers->uninstall($connection, 'integrity_versions');
    DB::table('integrity_versions')->where('sequence', 2)->delete();

    $triggers->install($connection, 'integrity_versions');

    expect(fn () => DB::table('integrity_versions')->delete())->toThrow(QueryException::class, 'append-only');
})->skip(fn () => in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true), 'DDL commits the test transaction on MySQL/MariaDB');
