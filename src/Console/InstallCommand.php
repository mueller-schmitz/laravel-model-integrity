<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

class InstallCommand extends Command
{
    protected $signature = 'model-integrity:install';

    protected $description = 'Publish the configuration and the migrations that are not published yet';

    private const string TIMESTAMP = '/^\d{4}_\d{2}_\d{2}_\d{6}_/';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'model-integrity-config']);

        $published = $this->publishMissingMigrations($files);

        $this->components->info($published === 0
            ? 'Migrations are already published.'
            : $published.' new '.($published === 1 ? 'migration' : 'migrations').' published.');

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Review config/model-integrity.php, then run: php artisan migrate');
        $this->line('  2. Restrict the application database user: php artisan model-integrity:grants');
        $this->line('  3. Add the HasIntegrity trait to your models');
        $this->line('  4. Schedule the verification: php artisan model-integrity:verify');

        return self::SUCCESS;
    }

    /**
     * Copies the package migrations that the application does not have yet,
     * compared by name without the timestamp. vendor:publish would copy all of
     * them again under new timestamps, duplicating the ones already run.
     */
    private function publishMissingMigrations(Filesystem $files): int
    {
        $target = database_path('migrations');
        $files->ensureDirectoryExists($target);

        /** @var list<string> $existingPaths */
        $existingPaths = $files->glob($target.'/*.php') ?: [];
        $existing = array_map(fn (string $path): string => $this->withoutTimestamp(basename($path)), $existingPaths);

        /** @var list<string> $source */
        $source = $files->glob(__DIR__.'/../../database/migrations/*.php') ?: [];
        sort($source);

        // New migrations must run after every existing one, even if an existing
        // migration carries a later timestamp than the current time.
        $moment = Carbon::now();
        $latest = $this->latestTimestamp($existingPaths);

        if ($latest !== null && $latest->greaterThanOrEqualTo($moment)) {
            $moment = $latest;
        }

        $published = 0;

        foreach ($source as $path) {
            $name = $this->withoutTimestamp(basename($path));

            if (in_array($name, $existing, true)) {
                continue;
            }

            // One second apart keeps the dependency order of the package migrations.
            $moment = $moment->addSecond();
            $files->copy($path, $target.'/'.$moment->format('Y_m_d_His').'_'.$name);
            $published++;
        }

        return $published;
    }

    /**
     * @param  list<string>  $paths
     */
    private function latestTimestamp(array $paths): ?Carbon
    {
        $latest = null;

        foreach ($paths as $path) {
            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', basename($path), $match) === 1) {
                $moment = Carbon::createFromFormat('Y_m_d_His', $match[1]);

                if ($moment !== null && ($latest === null || $moment->greaterThan($latest))) {
                    $latest = $moment;
                }
            }
        }

        return $latest;
    }

    private function withoutTimestamp(string $name): string
    {
        return (string) preg_replace(self::TIMESTAMP, '', $name);
    }
}
