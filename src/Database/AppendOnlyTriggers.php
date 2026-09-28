<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Database;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * Database triggers that reject UPDATE and DELETE on append-only tables, so
 * rows cannot be changed even by queries that bypass the application.
 *
 * TRUNCATE fires triggers only on PostgreSQL; on MySQL and MariaDB it is
 * prevented by privileges (no DROP), see the grants command.
 */
class AppendOnlyTriggers
{
    private const string MESSAGE = 'Recorded versions are append-only.';

    public function install(Connection $connection, string $table): void
    {
        $this->run($connection, $this->installStatements($connection, $table));
    }

    public function uninstall(Connection $connection, string $table): void
    {
        $this->run($connection, $this->uninstallStatements($connection, $table));
    }

    /**
     * @return list<string>
     */
    public function installStatements(Connection $connection, string $table): array
    {
        [$wrapped, $name] = $this->names($connection, $table);

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => [
                "CREATE TRIGGER `{$name}_append_only_update` BEFORE UPDATE ON {$wrapped} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'",
                "CREATE TRIGGER `{$name}_append_only_delete` BEFORE DELETE ON {$wrapped} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '".self::MESSAGE."'",
            ],
            'pgsql' => [
                "CREATE OR REPLACE FUNCTION \"{$name}_append_only\"() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION '".self::MESSAGE."'; END; \$\$",
                "CREATE TRIGGER \"{$name}_append_only\" BEFORE UPDATE OR DELETE ON {$wrapped} FOR EACH ROW EXECUTE FUNCTION \"{$name}_append_only\"()",
                "CREATE TRIGGER \"{$name}_append_only_truncate\" BEFORE TRUNCATE ON {$wrapped} FOR EACH STATEMENT EXECUTE FUNCTION \"{$name}_append_only\"()",
            ],
            'sqlite' => [
                "CREATE TRIGGER \"{$name}_append_only_update\" BEFORE UPDATE ON {$wrapped} BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END",
                "CREATE TRIGGER \"{$name}_append_only_delete\" BEFORE DELETE ON {$wrapped} BEGIN SELECT RAISE(ABORT, '".self::MESSAGE."'); END",
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function uninstallStatements(Connection $connection, string $table): array
    {
        [$wrapped, $name] = $this->names($connection, $table);

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb' => [
                "DROP TRIGGER IF EXISTS `{$name}_append_only_update`",
                "DROP TRIGGER IF EXISTS `{$name}_append_only_delete`",
            ],
            'pgsql' => [
                "DROP TRIGGER IF EXISTS \"{$name}_append_only\" ON {$wrapped}",
                "DROP TRIGGER IF EXISTS \"{$name}_append_only_truncate\" ON {$wrapped}",
                "DROP FUNCTION IF EXISTS \"{$name}_append_only\"()",
            ],
            'sqlite' => [
                "DROP TRIGGER IF EXISTS \"{$name}_append_only_update\"",
                "DROP TRIGGER IF EXISTS \"{$name}_append_only_delete\"",
            ],
            default => [],
        };
    }

    public function supports(Connection $connection): bool
    {
        return in_array($connection->getDriverName(), ['mysql', 'mariadb', 'pgsql', 'sqlite'], true);
    }

    /**
     * The quoted table name and the prefixed plain name used for trigger names.
     *
     * @return array{string, string}
     */
    private function names(Connection $connection, string $table): array
    {
        $name = $connection->getTablePrefix().$table;

        // Identifiers cannot be bound as parameters in DDL, so only plain
        // identifiers are accepted before they become part of the statements.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Table name [{$name}] must consist of letters, digits and underscores.");
        }

        return [$connection->getQueryGrammar()->wrapTable($table), $name];
    }

    /**
     * @param  list<string>  $statements
     */
    private function run(Connection $connection, array $statements): void
    {
        foreach ($statements as $statement) {
            // Unprepared: trigger bodies are not valid prepared statements on MySQL.
            // The only dynamic parts are identifiers validated in names().
            $connection->unprepared($statement); // @phpstan-ignore argument.type
        }
    }
}
