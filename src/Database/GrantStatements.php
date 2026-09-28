<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Database;

use InvalidArgumentException;

/**
 * Builds the SQL that restricts the application's database user: versions
 * may only be read and appended, the chain head only read, created and
 * updated. Comment lines start with "--".
 */
class GrantStatements
{
    /**
     * @param  list<string>  $otherTables  all other tables of the database (MySQL/MariaDB with $allTables)
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
    ): array {
        return match ($driver) {
            'mysql', 'mariadb' => $this->mysql($user, $host, $database, $versionsTable, $headsTable, $otherTables, $allTables),
            'pgsql' => $this->pgsql($user, $versionsTable, $headsTable),
            'sqlite' => [
                '-- SQLite has no users or privileges.',
                '-- Protect the database file with file system permissions instead.',
            ],
            default => throw new InvalidArgumentException("Privileges for driver [{$driver}] are not supported."),
        };
    }

    /**
     * @param  list<string>  $otherTables
     * @return list<string>
     */
    private function mysql(string $user, string $host, string $database, string $versions, string $heads, array $otherTables, bool $allTables): array
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
        }

        $lines[] = '-- Versions are append-only; the chain head is updated in place.';
        $lines[] = "GRANT SELECT, INSERT ON {$table($versions)} TO {$grantee};";
        $lines[] = "GRANT SELECT, INSERT, UPDATE ON {$table($heads)} TO {$grantee};";

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function pgsql(string $user, string $versions, string $heads): array
    {
        $role = $this->pgsqlIdentifier($user);

        return [
            '-- The user must not own these tables: owners can change or drop them regardless of privileges.',
            '-- Run migrations with a separate owner role.',
            "REVOKE ALL ON TABLE {$this->pgsqlIdentifier($versions)} FROM {$role};",
            "GRANT SELECT, INSERT ON TABLE {$this->pgsqlIdentifier($versions)} TO {$role};",
            "GRANT USAGE ON SEQUENCE {$this->pgsqlIdentifier($versions.'_id_seq')} TO {$role};",
            "REVOKE ALL ON TABLE {$this->pgsqlIdentifier($heads)} FROM {$role};",
            "GRANT SELECT, INSERT, UPDATE ON TABLE {$this->pgsqlIdentifier($heads)} TO {$role};",
        ];
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
