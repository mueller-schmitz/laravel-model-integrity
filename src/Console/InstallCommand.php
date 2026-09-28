<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallCommand extends Command
{
    protected $signature = 'model-integrity:install';

    protected $description = 'Publish the configuration and migrations of laravel-model-integrity';

    public function handle(Filesystem $files): int
    {
        $this->call('vendor:publish', ['--tag' => 'model-integrity-config']);

        // Laravel renames migrations with the current time on every publish,
        // so publishing again would create duplicates.
        if ($files->glob(database_path('migrations/*_create_integrity_versions_table.php')) !== []) {
            $this->components->info('Migrations are already published.');
        } else {
            $this->call('vendor:publish', ['--tag' => 'model-integrity-migrations']);
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Review config/model-integrity.php, then run: php artisan migrate');
        $this->line('  2. Restrict the application database user: php artisan model-integrity:grants');
        $this->line('  3. Add the HasIntegrity trait to your models');
        $this->line('  4. Schedule the verification: php artisan model-integrity:verify');

        return self::SUCCESS;
    }
}
