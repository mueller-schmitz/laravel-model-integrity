<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Console\Concerns\ResolvesModelClasses;
use MuellerSchmitz\ModelIntegrity\Database\AppendOnlyTriggers;

class TriggersCommand extends Command
{
    use ResolvesModelClasses;

    protected $signature = 'model-integrity:triggers
        {--remove : Remove the append-only triggers instead of installing them}
        {--connection= : Database connection (default: the integrity connection)}';

    protected $description = 'Install or remove the append-only triggers on the versions table';

    public function handle(AppendOnlyTriggers $triggers): int
    {
        $configured = config('model-integrity.connection');
        /** @var Connection $connection */
        $connection = DB::connection($this->stringOption('connection') ?? (is_string($configured) ? $configured : null));
        $table = Config::string('model-integrity.tables.versions');

        if (! $triggers->supports($connection)) {
            $this->warn("Append-only triggers are not available for the [{$connection->getDriverName()}] driver.");

            return self::SUCCESS;
        }

        if ($this->option('remove') === true) {
            $triggers->uninstall($connection, $table);
            $this->info("Append-only triggers removed from [{$table}].");
        } else {
            $triggers->install($connection, $table);
            $this->info("Append-only triggers installed on [{$table}].");
        }

        return self::SUCCESS;
    }
}
