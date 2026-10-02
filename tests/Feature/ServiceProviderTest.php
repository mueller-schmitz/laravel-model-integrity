<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\ModelIntegrityServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getProviders(ModelIntegrityServiceProvider::class))->toHaveCount(1);
});

it('registers the hashing services as singletons', function (string $class): void {
    expect(app($class))->toBeInstanceOf($class)->toBe(app($class));
})->with([CanonicalSerializer::class, Hasher::class, AnchorManager::class]);

it('merges the package config', function (): void {
    expect(config('model-integrity.tables'))->toBe([
        'versions' => 'integrity_versions',
        'heads' => 'integrity_heads',
        'files' => 'integrity_files',
        'anchors' => 'integrity_anchors',
        'anchor_proofs' => 'integrity_anchor_proofs',
        'subject_keys' => 'integrity_subject_keys',
    ])
        ->and(config('model-integrity.files'))->toBe(['disk' => 'local', 'path' => 'integrity-files'])
        ->and(config('model-integrity.defaults'))->toBe([
            'mode' => 'versioned',
            'deletes' => 'forbid',
            'except' => ['updated_at'],
        ])
        ->and(config('model-integrity.hash_format'))->toBe(1);
});

it('publishes the config under its tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(ModelIntegrityServiceProvider::class, 'model-integrity-config');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(config_path('model-integrity.php'));
});

it('publishes the migrations under its tag in dependency order', function (): void {
    $paths = ServiceProvider::pathsToPublish(ModelIntegrityServiceProvider::class, 'model-integrity-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(database_path('migrations'));

    // Timestamp prefixes keep the order when Laravel renames them on publish;
    // Laravel only rewrites names that already carry a timestamp.
    $files = array_map(basename(...), glob(array_key_first($paths).'/*.php'));
    sort($files);

    expect($files)->each->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_/')
        ->and(array_map(fn (string $file): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $file), $files))
        ->toBe([
            'create_integrity_versions_table.php',
            'create_integrity_heads_table.php',
            'create_integrity_append_only_triggers.php',
            'create_integrity_files_table.php',
            'create_integrity_files_append_only_triggers.php',
            'create_integrity_anchors_tables.php',
            'create_integrity_anchors_append_only_triggers.php',
            'create_integrity_subject_keys_table.php',
        ]);
});
