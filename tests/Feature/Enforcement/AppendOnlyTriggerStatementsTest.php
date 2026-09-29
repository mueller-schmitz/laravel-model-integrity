<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Database\AppendOnlyTriggers;

it('rejects table names that are no plain identifiers', function (string $table): void {
    app(AppendOnlyTriggers::class)->installStatements(DB::connection(), $table);
})->with([
    'quote' => ["versions'; DROP TABLE users; --"],
    'backtick' => ['ver`sions'],
    'double quote' => ['ver"sions'],
    'space' => ['my versions'],
    'empty' => [''],
])->throws(InvalidArgumentException::class);

it('drops existing triggers before creating them, so installing is idempotent', function (): void {
    $triggers = app(AppendOnlyTriggers::class);
    $uninstall = $triggers->uninstallStatements(DB::connection(), 'integrity_versions');

    expect(array_slice($triggers->installStatements(DB::connection(), 'integrity_versions'), 0, count($uninstall)))->toBe($uninstall);
});

it('rejects table names whose trigger names would exceed the identifier limit', function (): void {
    app(AppendOnlyTriggers::class)->installStatements(DB::connection(), str_repeat('t', 50));
})->throws(InvalidArgumentException::class, '63');

it('does not put the table name into the error message', function (): void {
    $statements = implode("\n", app(AppendOnlyTriggers::class)->installStatements(DB::connection(), 'integrity_versions'));

    expect($statements)->toContain("'Integrity records are append-only.'");
});
