<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Database\GrantStatements;

beforeEach(function (): void {
    $this->grants = new GrantStatements;
});

function sqlOnly(array $lines): array
{
    return array_values(array_filter($lines, fn (string $line): bool => ! str_starts_with($line, '--') && $line !== ''));
}

it('grants append-only privileges on MySQL and MariaDB', function (string $driver): void {
    $lines = $this->grants->build($driver, 'app', '10.0.0.%', 'shop', 'integrity_versions', 'integrity_heads');

    expect(sqlOnly($lines))->toBe([
        "GRANT SELECT, INSERT ON `shop`.`integrity_versions` TO 'app'@'10.0.0.%';",
        "GRANT SELECT, INSERT, UPDATE ON `shop`.`integrity_heads` TO 'app'@'10.0.0.%';",
    ]);

    expect(implode("\n", $lines))->toContain('database-wide');
})->with(['mysql', 'mariadb']);

it('replaces database-wide privileges by table privileges on MySQL', function (): void {
    $lines = $this->grants->build('mysql', 'app', '%', 'shop', 'integrity_versions', 'integrity_heads', ['invoices', 'users'], allTables: true);

    expect(sqlOnly($lines))->toBe([
        "REVOKE ALL PRIVILEGES ON `shop`.* FROM 'app'@'%';",
        "GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.`invoices` TO 'app'@'%';",
        "GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.`users` TO 'app'@'%';",
        "GRANT SELECT, INSERT ON `shop`.`integrity_versions` TO 'app'@'%';",
        "GRANT SELECT, INSERT, UPDATE ON `shop`.`integrity_heads` TO 'app'@'%';",
    ]);
});

it('keeps read access to views when replacing database-wide privileges', function (): void {
    $lines = $this->grants->build('mysql', 'app', '%', 'shop', 'integrity_versions', 'integrity_heads', ['invoices'], allTables: true, views: ['invoice_totals']);

    expect(sqlOnly($lines))->toContain("GRANT SELECT ON `shop`.`invoice_totals` TO 'app'@'%';");
});

it('rejects an empty user', function (string $driver): void {
    $this->grants->build($driver, '', '%', 'shop', 'integrity_versions', 'integrity_heads');
})->with(['mysql', 'pgsql'])->throws(InvalidArgumentException::class, 'user');

it('restricts table privileges on PostgreSQL', function (): void {
    $lines = $this->grants->build('pgsql', 'app', '%', 'shop', 'integrity_versions', 'integrity_heads');

    expect(sqlOnly($lines))->toBe([
        'REVOKE ALL ON TABLE "integrity_versions" FROM "app";',
        'GRANT SELECT, INSERT ON TABLE "integrity_versions" TO "app";',
        'GRANT USAGE ON SEQUENCE "integrity_versions_id_seq" TO "app";',
        'REVOKE ALL ON TABLE "integrity_heads" FROM "app";',
        'GRANT SELECT, INSERT, UPDATE ON TABLE "integrity_heads" TO "app";',
    ]);

    expect(implode("\n", $lines))->toContain('must not own')->toContain('USAGE ON SCHEMA');
});

it('explains that SQLite has no privileges', function (): void {
    $lines = $this->grants->build('sqlite', 'app', '%', 'db', 'integrity_versions', 'integrity_heads');

    expect(sqlOnly($lines))->toBe([])
        ->and(implode("\n", $lines))->toContain('no users');
});

it('quotes identifiers', function (): void {
    $lines = $this->grants->build('mysql', "o'brien", '%', 'my`db', 'integrity_versions', 'integrity_heads');

    expect(sqlOnly($lines)[0])->toBe("GRANT SELECT, INSERT ON `my``db`.`integrity_versions` TO 'o''brien'@'%';");
});

it('rejects unsupported drivers', function (): void {
    $this->grants->build('sqlsrv', 'app', '%', 'db', 'integrity_versions', 'integrity_heads');
})->throws(InvalidArgumentException::class, 'sqlsrv');
