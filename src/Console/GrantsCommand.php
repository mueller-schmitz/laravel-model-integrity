<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Console\Concerns\ResolvesModelClasses;
use MuellerSchmitz\ModelIntegrity\Database\GrantStatements;

class GrantsCommand extends Command
{
    use ResolvesModelClasses;

    protected $signature = 'model-integrity:grants
        {--user= : Database user of the application (default: the connection user)}
        {--host=% : Host part of the MySQL/MariaDB account}
        {--connection= : Database connection (default: the integrity connection)}
        {--all-tables : MySQL/MariaDB: replace database-wide privileges by table privileges}';

    protected $description = 'Print the SQL that restricts the application user to append-only access';

    public function handle(GrantStatements $grants): int
    {
        /** @var Connection $connection */
        $connection = DB::connection($this->connectionName());
        $prefix = $connection->getTablePrefix();
        $versions = $prefix.Config::string('model-integrity.tables.versions');
        $heads = $prefix.Config::string('model-integrity.tables.heads');
        // Without --all-tables printed without querying the database; a GRANT on a table that
        // is not migrated yet fails on MySQL, so the output says to migrate first.
        $appendOnly = [
            $prefix.Config::string('model-integrity.tables.files', 'integrity_files'),
            $prefix.Config::string('model-integrity.tables.anchors', 'integrity_anchors'),
            $prefix.Config::string('model-integrity.tables.anchor_proofs', 'integrity_anchor_proofs'),
        ];
        $updatable = [$prefix.Config::string('model-integrity.tables.subject_keys', 'integrity_subject_keys')];

        $driver = $connection->getDriverName();
        $user = $this->user($connection);
        $host = $this->stringOption('host') ?? '%';
        $allTables = $this->option('all-tables') === true;

        if ($user === '' && $driver !== 'sqlite') {
            $this->error('The connection has no database user; pass --user.');

            return self::INVALID;
        }

        if ($driver === 'pgsql' && ($host !== '%' || $allTables)) {
            $this->warn('--host and --all-tables are ignored on PostgreSQL: roles have no host and privileges are granted per table.');
        }

        $lines = $grants->build(
            $driver,
            $user,
            $host,
            $connection->getDatabaseName(),
            $versions,
            $heads,
            $allTables ? $this->otherTables($connection, [$versions, $heads, ...$appendOnly, ...$updatable]) : [],
            $allTables,
            $allTables ? $this->views($connection) : [],
            $appendOnly,
            $updatable,
        );

        foreach ($lines as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function connectionName(): ?string
    {
        $configured = config('model-integrity.connection');

        return $this->stringOption('connection') ?? (is_string($configured) ? $configured : null);
    }

    private function user(Connection $connection): string
    {
        $configured = $connection->getConfig('username');

        return $this->stringOption('user') ?? (is_string($configured) ? $configured : '');
    }

    /**
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private function otherTables(Connection $connection, array $exclude): array
    {
        $database = $connection->getDatabaseName();

        $tables = [];

        foreach ($connection->getSchemaBuilder()->getTables() as $table) {
            if (($table['schema'] ?? $database) === $database && ! in_array($table['name'], $exclude, true)) {
                $tables[] = $table['name'];
            }
        }

        sort($tables);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function views(Connection $connection): array
    {
        $database = $connection->getDatabaseName();
        $views = [];

        foreach ($connection->getSchemaBuilder()->getViews() as $view) {
            if (($view['schema'] ?? $database) === $database) {
                $views[] = $view['name'];
            }
        }

        sort($views);

        return $views;
    }
}
