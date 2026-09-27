<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the versions table with all columns', function (): void {
    expect(Schema::hasColumns('integrity_versions', [
        'id',
        'sequence',
        'versionable_type',
        'versionable_id',
        'version',
        'event',
        'hash_format',
        'schema_version',
        'snapshot',
        'prev_hash',
        'global_prev_hash',
        'hash',
        'actor_type',
        'actor_id',
        'reason',
        'context',
        'created_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('integrity_versions', 'updated_at'))->toBeFalse();
});

it('enforces a unique global sequence and unique versions per model', function (): void {
    $indexes = collect(Schema::getIndexes('integrity_versions'))
        ->where('unique', true)
        ->pluck('columns')
        ->all();

    expect($indexes)->toContain(['sequence'])
        ->toContain(['hash'])
        ->toContain(['versionable_type', 'versionable_id', 'version']);
});

it('creates the heads table with the global head', function (): void {
    expect(Schema::hasColumns('integrity_heads', ['chain', 'sequence', 'hash', 'updated_at']))->toBeTrue();

    $head = DB::table('integrity_heads')->where('chain', 'global')->first();

    expect($head)->not->toBeNull()
        ->and((int) $head->sequence)->toBe(0)
        ->and($head->hash)->toBeNull();
});
