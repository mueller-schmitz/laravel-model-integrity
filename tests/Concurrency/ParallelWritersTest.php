<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use Symfony\Component\Process\Process;

const WORKERS = 8;
const WRITES_PER_WORKER = 20;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite serializes writes and has no row locks.');
    }
});

it('keeps both chains gapless and valid under parallel writers', function (): void {
    $shared = Invoice::query()->create(['number' => 'SHARED', 'total' => '0.00']);

    $started = microtime(true);

    $processes = collect(range(1, WORKERS))->map(function (int $worker) use ($shared): Process {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/worker.php', (string) $worker, (string) WRITES_PER_WORKER, (string) $shared->getKey()],
            timeout: 300,
        );
        $process->start();

        return $process;
    });

    $processes->each(fn (Process $process) => $process->wait());

    $elapsed = microtime(true) - $started;

    $failures = $processes
        ->reject(fn (Process $process): bool => $process->isSuccessful())
        ->map(fn (Process $process): string => trim($process->getErrorOutput().$process->getOutput()))
        ->values()
        ->all();

    expect($failures)->toBe([]);

    // 1 shared create + per worker: 1 create, n own updates, n shared updates
    $expected = 1 + WORKERS * (1 + 2 * WRITES_PER_WORKER);

    fwrite(STDERR, sprintf(
        "\n%d versions by %d parallel writers in %.2f s (%.0f versions/s) on %s\n",
        $expected, WORKERS, $elapsed, $expected / $elapsed, DB::connection()->getDriverName(),
    ));

    $sequences = DB::table('integrity_versions')->orderBy('sequence')->pluck('sequence')->map(fn ($s): int => (int) $s)->all();

    expect($sequences)->toBe(range(1, $expected));

    $head = DB::table('integrity_heads')->where('chain', 'global')->first();
    $last = DB::table('integrity_versions')->where('sequence', $expected)->first();

    expect((int) $head->sequence)->toBe($expected)
        ->and($head->hash)->toBe($last->hash)
        ->and($shared->integrityVersions()->pluck('version')->map(fn ($v): int => (int) $v)->all())
        ->toBe(range(1, 1 + WORKERS * WRITES_PER_WORKER));

    $result = app(IntegrityChecker::class)->checkAll();

    expect($result->errors()->map(fn (IntegrityError $error): string => (string) $error)->all())->toBe([])
        ->and($result->checkedVersions())->toBe($expected);
});
