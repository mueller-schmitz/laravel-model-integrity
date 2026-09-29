<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

const ALL_MIGRATIONS = [
    'create_integrity_versions_table.php',
    'create_integrity_heads_table.php',
    'create_integrity_append_only_triggers.php',
    'create_integrity_files_table.php',
    'create_integrity_files_append_only_triggers.php',
];

function publishedMigrations(): array
{
    return File::glob(database_path('migrations/*_create_integrity_*.php'));
}

/**
 * @return list<string> published migration names without their timestamp, in file order
 */
function publishedMigrationNames(): array
{
    return array_map(fn (string $path): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)), publishedMigrations());
}

function removePublishedFiles(): void
{
    File::delete(config_path('model-integrity.php'));
    File::delete(publishedMigrations());
}

beforeEach(fn () => removePublishedFiles());
afterEach(fn () => removePublishedFiles());

it('publishes config and migrations and names the next steps', function (): void {
    $this->artisan('model-integrity:install')
        ->expectsOutputToContain('php artisan migrate')
        ->expectsOutputToContain('model-integrity:grants')
        ->expectsOutputToContain('model-integrity:verify')
        ->assertExitCode(0);

    expect(File::exists(config_path('model-integrity.php')))->toBeTrue()
        ->and(publishedMigrationNames())->toBe(ALL_MIGRATIONS);
});

it('does not publish the migrations twice', function (): void {
    $this->artisan('model-integrity:install')->assertExitCode(0);

    $this->artisan('model-integrity:install')
        ->expectsOutputToContain('already published')
        ->assertExitCode(0);

    expect(publishedMigrations())->toHaveCount(count(ALL_MIGRATIONS));
});

it('publishes only the migrations missing after an upgrade', function (): void {
    // An application that installed v0.1: three migrations under older timestamps.
    $source = dirname(__DIR__, 3).'/database/migrations';

    foreach (array_slice(ALL_MIGRATIONS, 0, 3) as $i => $name) {
        File::copy(
            File::glob($source.'/*_'.$name)[0],
            database_path('migrations/2026_10_01_00000'.$i.'_'.$name),
        );
    }

    $this->artisan('model-integrity:install')
        ->expectsOutputToContain('2 new migrations')
        ->assertExitCode(0);

    $names = publishedMigrationNames();

    expect($names)->toBe(ALL_MIGRATIONS)
        // The new ones are dated after the existing ones, so they run after them.
        ->and(basename(publishedMigrations()[3]) > basename(publishedMigrations()[2]))->toBeTrue();
});
