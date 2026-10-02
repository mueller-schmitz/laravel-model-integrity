<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite serializes writes and has no row locks.');
    }
});

it('creates exactly one key when several processes record data of a new subject at once', function (): void {
    // The customer row itself is not recorded: only its key is created by the workers.
    $customerId = DB::table('customers')->insertGetId(['number' => 'C-1', 'name' => 'Ada', 'created_at' => now(), 'updated_at' => now()]);

    $processes = collect(range(1, 6))->map(function (int $worker) use ($customerId): Process {
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', 'orders', (string) $worker, '5', (string) $customerId], timeout: 300);
        $process->start();

        return $process;
    });

    $processes->each(fn (Process $process) => $process->wait());

    $failures = $processes->reject(fn (Process $process): bool => $process->isSuccessful())
        ->map(fn (Process $process): string => trim($process->getErrorOutput().$process->getOutput()))
        ->values()
        ->all();

    expect($failures)->toBe([]);

    $keyIds = Version::query()->where('versionable_type', '!=', 'model-integrity.subject')->get()
        ->map(fn (Version $version): string => $version->snapshot['shipping_name']['@encrypted']['k'])
        ->unique()
        ->values()
        ->all();

    expect(DB::table('integrity_subject_keys')->count())->toBe(1)
        ->and(Version::query()->where('versionable_type', 'model-integrity.subject')->count())->toBe(1)
        ->and($keyIds)->toBe([DB::table('integrity_subject_keys')->value('id')])
        ->and(DB::table('integrity_versions')->orderBy('sequence')->pluck('sequence')->map(fn ($s): int => (int) $s)->all())->toBe(range(1, 31));

    $result = app(IntegrityChecker::class)->checkChain();

    expect($result->errors()->map(fn (IntegrityError $error): string => (string) $error)->all())->toBe([]);
});
