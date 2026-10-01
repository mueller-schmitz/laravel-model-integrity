<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Database;

use InvalidArgumentException;

/**
 * Builds the SQL that restricts the application's database user: versions,
 * stored files and anchors may only be read and appended, the chain heads only
 * read, created and updated. Comment lines start with "--".
 */
class GrantStatements
{
    /**
     * @param  list<string>  $otherTables  all other tables of the database (MySQL/MariaDB with $allTables)
     * @param  list<string>  $views  all views of the database (MySQL/MariaDB with $allTables)
     * @param  list<string>  $appendOnlyTables  further append-only tables besides the versions
     * @return list<string>
     */
    public function build(
        string $driver,
        string $user,
        string $host,
        string $database,
        string $versionsTable,
        string $headsTable,
        array $otherTables = [],
        bool $allTables = false,
        array $views = [],
        array $appendOnlyTables = [],
    ): array {
        if ($user === '' && $driver !== 'sqlite') {
            throw new InvalidArgumentException('The database user must not be empty.');
        }

        $preamble = ['-- Run php artisan migrate first: the statements refer to all integrity tables.'];

        return [...$preamble, ...match ($driver) {
            'mysql', 'mariadb' => $this->mysql($user, $host, $database, $versionsTable, $headsTable, $otherTables, $views, $allTables, $appendOnlyTables),
            'pgsql' => $this->pgsql($user, $versionsTable, $headsTable, $appendOnlyTables),
            'sqlite' => [
                '-- SQLite has no users or privileges.',
                '-- Protect the database file with file system permissions instead.',
            ],
            default => throw new InvalidArgumentException("Privileges for driver [{$driver}] are not supported."),
        }];
    }

    /**
     * @param  list<string>  $otherTables
     * @param  list<string>  $views
     * @param  list<string>  $appendOnly
     * @return list<string>
     */
    private function mysql(string $user, string $host, string $database, string $versions, string $heads, array $otherTables, array $views, bool $allTables, array $appendOnly): array
    {
        $grantee = $this->mysqlString($user).'@'.$this->mysqlString($host);
        $table = fn (string $name): string => $this->mysqlIdentifier($database).'.'.$this->mysqlIdentifier($name);

        $lines = [
            '-- Run migrations with a separate user; the application user must not alter or drop tables.',
            '-- MySQL cannot narrow database-wide privileges (GRANT ... ON db.*) per table.',
            $allTables
                ? '-- The database-wide privileges are therefore replaced by table privileges.'
                : '-- If the user has database-wide privileges, use --all-tables to replace them by table privileges.',
        ];

        if ($allTables) {
            $lines[] = '-- REVOKE fails if the user has no database-wide grant; skip it in that case.';
            $lines[] = "REVOKE ALL PRIVILEGES ON {$this->mysqlIdentifier($database)}.* FROM {$grantee};";

            foreach ($otherTables as $other) {
                $lines[] = "GRANT SELECT, INSERT, UPDATE, DELETE ON {$table($other)} TO {$grantee};";
            }

            foreach ($views as $view) {
                $lines[] = "GRANT SELECT ON {$table($view)} TO {$grantee};";
            }

            $lines[] = "-- Global privileges (GRANT ... ON *.*) and accounts with other hosts than '{$host}' are not covered.";
        }

        $lines[] = '-- Versions, files and anchors are append-only; the chain heads are updated in place.';
        $lines[] = "GRANT SELECT, INSERT ON {$table($versions)} TO {$grantee};";
        $lines[] = "GRANT SELECT, INSERT, UPDATE ON {$table($heads)} TO {$grantee};";

        foreach ($appendOnly as $appendOnlyTable) {
            $lines[] = "GRANT SELECT, INSERT ON {$table($appendOnlyTable)} TO {$grantee};";
        }

        return $lines;
    }

    /**
     * @param  list<string>  $appendOnly
     * @return list<string>
     */
    private function pgsql(string $user, string $versions, string $heads, array $appendOnly): array
    {
        $role = $this->pgsqlIdentifier($user);

        $lines = [
            '-- The user must not own these tables: owners can change or drop them regardless of privileges.',
            '-- Run migrations with a separate owner role.',
            '-- Tables outside the public schema also need: GRANT USAGE ON SCHEMA <schema> TO '.$role.';',
            "REVOKE ALL ON TABLE {$this->pgsqlIdentifier($versions)} FROM {$role};",
            "GRANT SELECT, INSERT ON TABLE {$this->pgsqlIdentifier($versions)} TO {$role};",
            "GRANT USAGE ON SEQUENCE {$this->pgsqlIdentifier($versions.'_id_seq')} TO {$role};",
            "REVOKE ALL ON TABLE {$this->pgsqlIdentifier($heads)} FROM {$role};",
            "GRANT SELECT, INSERT, UPDATE ON TABLE {$this->pgsqlIdentifier($heads)} TO {$role};",
        ];

        foreach ($appendOnly as $table) {
            $lines[] = "REVOKE ALL ON TABLE {$this->pgsqlIdentifier($table)} FROM {$role};";
            $lines[] = "GRANT SELECT, INSERT ON TABLE {$this->pgsqlIdentifier($table)} TO {$role};";
            $lines[] = "GRANT USAGE ON SEQUENCE {$this->pgsqlIdentifier($table.'_id_seq')} TO {$role};";
        }

        return $lines;
    }

    private function mysqlIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private function mysqlString(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private function pgsqlIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
