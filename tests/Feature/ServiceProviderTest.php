<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\ModelIntegrityServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getProviders(ModelIntegrityServiceProvider::class))->toHaveCount(1);
});

it('registers the hashing services as singletons', function (string $class): void {
    expect(app($class))->toBeInstanceOf($class)->toBe(app($class));
})->with([CanonicalSerializer::class, Hasher::class]);

it('merges the package config', function (): void {
    expect(config('model-integrity.tables'))->toBe([
        'versions' => 'integrity_versions',
        'heads' => 'integrity_heads',
    ])
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

it('publishes both migrations under its tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(ModelIntegrityServiceProvider::class, 'model-integrity-migrations');

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths)[0])->toBe(database_path('migrations'));

    $source = array_key_first($paths);

    expect(glob($source.'/*.php'))->toHaveCount(2);
});
