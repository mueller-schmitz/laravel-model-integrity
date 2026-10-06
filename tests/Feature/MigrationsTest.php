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

it('keeps index names within the identifier limit of every supported database', function (): void {
    // MySQL and MariaDB reject identifiers longer than 64 characters,
    // PostgreSQL silently truncates them to 63.
    $tooLong = collect(Schema::getIndexes('integrity_versions'))
        ->pluck('name')
        ->filter(fn (string $name): bool => strlen($name) > 63)
        ->values()
        ->all();

    expect($tooLong)->toBe([]);
});

it('creates the heads table with the global head', function (): void {
    expect(Schema::hasColumns('integrity_heads', ['chain', 'sequence', 'hash', 'updated_at']))->toBeTrue();

    $head = DB::table('integrity_heads')->where('chain', 'global')->first();

    expect($head)->not->toBeNull()
        ->and((int) $head->sequence)->toBe(0)
        ->and($head->hash)->toBeNull();
});

it('creates the anchor tables and the anchors head', function (): void {
    expect(Schema::hasColumns('integrity_anchors', ['id', 'anchor_format', 'from_sequence', 'to_sequence', 'merkle_root', 'prev_digest', 'digest', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumns('integrity_anchor_proofs', ['id', 'anchor_id', 'driver', 'proof', 'created_at']))->toBeTrue()
        ->and(collect(Schema::getIndexes('integrity_anchors'))->where('unique', true)->pluck('columns')->all())->toContain(['to_sequence'])->toContain(['digest']);

    $head = DB::table('integrity_heads')->where('chain', 'anchors')->first();

    expect($head)->not->toBeNull()
        ->and((int) $head->sequence)->toBe(0)
        ->and($head->hash)->toBeNull();
});

it('keeps the index names of all integrity tables within the identifier limit', function (): void {
    $tooLong = collect(['integrity_versions', 'integrity_files', 'integrity_anchors', 'integrity_anchor_proofs', 'integrity_subject_keys'])
        ->flatMap(fn (string $table): array => array_column(Schema::getIndexes($table), 'name'))
        ->filter(fn (string $name): bool => strlen($name) > 63)
        ->values()
        ->all();

    expect($tooLong)->toBe([]);
});

it('adds the key of the data subject to the files table', function (): void {
    expect(Schema::hasColumns('integrity_files', ['id', 'sha256', 'disk', 'path', 'size', 'mime', 'created_at', 'key_id']))->toBeTrue();
});

it('creates the subject keys table with a unique subject', function (): void {
    expect(Schema::hasColumns('integrity_subject_keys', ['id', 'subject', 'key', 'created_at', 'shredded_at']))->toBeTrue()
        ->and(collect(Schema::getIndexes('integrity_subject_keys'))->where('unique', true)->pluck('columns')->all())->toContain(['subject']);
});
