<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use MuellerSchmitz\ModelIntegrity\Anchoring\Anchorer;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite serializes writes and has no row locks.');
    }

    $this->anchorsRoot = sys_get_temp_dir().'/mi-anchors-'.bin2hex(random_bytes(4));
    config([
        'filesystems.disks.anchors' => ['driver' => 'local', 'root' => $this->anchorsRoot],
        'model-integrity.anchors' => ['drivers' => ['disk'], 'disk' => ['disk' => 'anchors', 'path' => 'statements']],
    ]);
});

afterEach(function (): void {
    if (isset($this->anchorsRoot)) {
        File::deleteDirectory($this->anchorsRoot);
    }
});

it('anchors every version exactly once while writers and other anchor runs are active', function (): void {
    $shared = Invoice::query()->create(['number' => 'SHARED', 'total' => '0.00']);
    $env = ['MI_ANCHORS_ROOT' => $this->anchorsRoot];

    $processes = collect([
        ...array_map(fn (int $worker): array => ['models', $worker, 15], range(1, 4)),
        ...array_map(fn (int $worker): array => ['anchor', $worker, 10], range(5, 7)),
    ])->map(function (array $job) use ($shared, $env): Process {
        [$mode, $worker, $writes] = $job;
        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', $mode, (string) $worker, (string) $writes, (string) $shared->id], env: $env, timeout: 300);
        $process->start();

        return $process;
    });

    $processes->each(fn (Process $process) => $process->wait());

    $failures = $processes->reject(fn (Process $process): bool => $process->isSuccessful())
        ->map(fn (Process $process): string => trim($process->getErrorOutput().$process->getOutput()))
        ->values()
        ->all();

    expect($failures)->toBe([]);

    // Anchor the rest, then the anchors must cover 1..n without overlap or gap.
    app(Anchorer::class)->anchor();

    $ranges = DB::table('integrity_anchors')->orderBy('id')->get(['from_sequence', 'to_sequence'])
        ->map(fn (object $row): array => [(int) $row->from_sequence, (int) $row->to_sequence])
        ->all();
    $covered = array_merge(...array_map(fn (array $range): array => range($range[0], $range[1]), $ranges));

    expect(count($ranges))->toBeGreaterThan(1)
        ->and($covered)->toBe(range(1, (int) DB::table('integrity_versions')->max('sequence')));

    $result = app(IntegrityChecker::class)->checkAll();

    expect($result->errors()->map(fn (IntegrityError $error): string => (string) $error)->all())->toBe([]);
});
