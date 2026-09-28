<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;
use MuellerSchmitz\ModelIntegrity\Models\Version;

function insertVersionRow(array $overrides = []): Version
{
    DB::table('integrity_versions')->insert(array_merge([
        'sequence' => 1,
        'versionable_type' => 'App\Models\Invoice',
        'versionable_id' => '42',
        'version' => 1,
        'event' => 'created',
        'hash_format' => 1,
        'schema_version' => 1,
        'snapshot' => '{"id":42,"total":"100.00"}',
        'prev_hash' => null,
        'global_prev_hash' => null,
        'hash' => str_repeat('a', 64),
        'actor_type' => 'App\Models\User',
        'actor_id' => '7',
        'reason' => 'Initial',
        'context' => '{"ip":"127.0.0.1"}',
        'created_at' => '2026-09-28 10:05:00.123456',
    ], $overrides));

    return Version::query()->firstOrFail();
}

it('reads a version with typed attributes', function (): void {
    $version = insertVersionRow();

    expect($version->sequence)->toBe(1)
        ->and($version->version)->toBe(1)
        ->and($version->hash_format)->toBe(1)
        ->and($version->schema_version)->toBe(1)
        ->and($version->snapshot)->toBe(['id' => 42, 'total' => '100.00'])
        ->and($version->context)->toBe(['ip' => '127.0.0.1']);
});

it('returns snapshot and context with canonical key order', function (): void {
    // MySQL's native JSON type reorders keys on storage.
    $version = insertVersionRow([
        'snapshot' => '{"total":"100.00","id":42,"meta":{"b":1,"a":2}}',
        'context' => '{"source":"web","ip":"127.0.0.1"}',
    ]);

    expect($version->snapshot)->toBe(['id' => 42, 'meta' => ['a' => 2, 'b' => 1], 'total' => '100.00'])
        ->and($version->context)->toBe(['ip' => '127.0.0.1', 'source' => 'web']);
});

it('reads created_at as utc regardless of the app timezone', function (): void {
    config(['app.timezone' => 'Europe/Berlin']);
    date_default_timezone_set('Europe/Berlin');

    try {
        $version = insertVersionRow();

        expect($version->created_at->getTimezone()->getName())->toBe('UTC')
            ->and($version->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-09-28 10:05:00.123456');
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('builds the hash envelope from the stored row', function (): void {
    expect(insertVersionRow()->toEnvelope())->toBe([
        'format' => 1,
        'sequence' => 1,
        'versionable_type' => 'App\Models\Invoice',
        'versionable_id' => '42',
        'version' => 1,
        'event' => 'created',
        'schema_version' => 1,
        'snapshot' => ['id' => 42, 'total' => '100.00'],
        'prev_hash' => null,
        'global_prev_hash' => null,
        'actor_type' => 'App\Models\User',
        'actor_id' => '7',
        'reason' => 'Initial',
        'context' => ['ip' => '127.0.0.1'],
        'created_at' => '2026-09-28T10:05:00.123456Z',
    ]);
});

it('refuses to update a stored version', function (): void {
    $version = insertVersionRow();
    $version->reason = 'Changed';

    $version->save();
})->throws(ImmutableModelException::class);

it('refuses to delete a stored version', function (): void {
    insertVersionRow()->delete();
})->throws(ImmutableModelException::class);

it('uses the configured table', function (): void {
    config(['model-integrity.tables.versions' => 'custom_versions']);

    expect((new Version)->getTable())->toBe('custom_versions');
});
