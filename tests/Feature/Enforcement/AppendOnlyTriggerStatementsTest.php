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

it('does not put the table name into the error message', function (): void {
    $statements = implode("\n", app(AppendOnlyTriggers::class)->installStatements(DB::connection(), 'integrity_versions'));

    expect($statements)->toContain("'Recorded versions are append-only.'");
});
