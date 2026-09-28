<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

function publishedMigrations(): array
{
    return File::glob(database_path('migrations/*_create_integrity_*.php'));
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
        ->and(array_map(fn (string $path): string => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)), publishedMigrations()))
        ->toBe([
            'create_integrity_versions_table.php',
            'create_integrity_heads_table.php',
            'create_integrity_append_only_triggers.php',
        ]);
});

it('does not publish the migrations twice', function (): void {
    $this->artisan('model-integrity:install')->assertExitCode(0);

    $this->artisan('model-integrity:install')
        ->expectsOutputToContain('already published')
        ->assertExitCode(0);

    expect(publishedMigrations())->toHaveCount(3);
});
