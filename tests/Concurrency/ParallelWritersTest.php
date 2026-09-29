<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Post;
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

/**
 * Starts all workers at once and returns the failure output of each failed one.
 *
 * @return list<string>
 */
function runWorkers(string $mode, int|string $sharedId, string $label, int $expectedVersions): array
{
    $started = microtime(true);

    /** @var Collection<int, Process> $processes */
    $processes = collect(range(1, WORKERS))->map(function (int $worker) use ($mode, $sharedId): Process {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/worker.php', $mode, (string) $worker, (string) WRITES_PER_WORKER, (string) $sharedId],
            timeout: 300,
        );
        $process->start();

        return $process;
    });

    $processes->each(fn (Process $process) => $process->wait());

    $elapsed = microtime(true) - $started;
    $versions = $expectedVersions > 0 ? $expectedVersions : (int) DB::table('integrity_versions')->count();
    fwrite(STDERR, sprintf(
        "\n%s: %d versions by %d parallel writers in %.2f s (%.0f versions/s) on %s\n",
        $label, $versions, WORKERS, $elapsed, $versions / max($elapsed, 0.001), DB::connection()->getDriverName(),
    ));

    return $processes
        ->reject(fn (Process $process): bool => $process->isSuccessful())
        ->map(fn (Process $process): string => trim($process->getErrorOutput().$process->getOutput()))
        ->values()
        ->all();
}

function expectIntactChains(int $expectedVersions): void
{
    $sequences = DB::table('integrity_versions')->orderBy('sequence')->pluck('sequence')->map(fn ($s): int => (int) $s)->all();

    expect($sequences)->toBe(range(1, $expectedVersions));

    $head = DB::table('integrity_heads')->where('chain', 'global')->first();

    expect((int) $head->sequence)->toBe($expectedVersions)
        ->and($head->hash)->toBe(DB::table('integrity_versions')->where('sequence', $expectedVersions)->value('hash'));

    $result = app(IntegrityChecker::class)->checkAll();

    expect($result->errors()->map(fn (IntegrityError $error): string => (string) $error)->all())->toBe([])
        ->and($result->checkedVersions())->toBe($expectedVersions);
}

it('keeps both chains gapless and valid under parallel model writes', function (): void {
    $shared = Invoice::query()->create(['number' => 'SHARED', 'total' => '0.00']);

    // 1 shared create + per worker: 1 create, n own updates, n shared updates
    $expected = 1 + WORKERS * (1 + 2 * WRITES_PER_WORKER);

    expect(runWorkers('models', $shared->getKey(), 'models', $expected))->toBe([]);

    expectIntactChains($expected);

    expect($shared->integrityVersions()->pluck('version')->map(fn ($v): int => (int) $v)->all())
        ->toBe(range(1, 1 + WORKERS * WRITES_PER_WORKER));
});

it('records the final state in deleted versions under parallel updates', function (): void {
    $shared = collect(range(1, WORKERS))->map(fn (int $i) => Invoice::query()->create(['number' => "S{$i}", 'total' => '0.00']));

    expect(runWorkers('deletes', $shared->pluck('id')->implode(','), 'deletes', 0))->toBe([]);

    $max = (int) DB::table('integrity_versions')->max('sequence');
    expectIntactChains($max);

    // A deletion changes no data, so the deleted version's snapshot must equal
    // the snapshot of the version recorded right before it.
    $deleted = Version::query()->where('event', 'deleted')->get();

    expect($deleted)->toHaveCount(WORKERS);

    foreach ($deleted as $version) {
        $before = Version::query()
            ->where('versionable_type', $version->versionable_type)
            ->where('versionable_id', $version->versionable_id)
            ->where('version', $version->version - 1)
            ->sole();

        expect($version->snapshot)->toBe($before->snapshot);
    }
});

it('stores identical files once under parallel writers', function (): void {
    $root = sys_get_temp_dir().'/mi-files-'.uniqid();
    putenv("MI_FILES_ROOT={$root}");
    config(['filesystems.disks.integrity' => ['driver' => 'local', 'root' => $root], 'model-integrity.files.disk' => 'integrity']);

    try {
        // Per worker and write one unique file, plus one file shared by all.
        $expected = 1 + WORKERS * WRITES_PER_WORKER;

        expect(runWorkers('files', 0, 'files', $expected))->toBe([])
            ->and(DB::table('integrity_files')->count())->toBe($expected)
            ->and(DB::table('integrity_files')->where('sha256', hash('sha256', 'shared content'))->count())->toBe(1);

        expectIntactChains($expected);

        expect(app(IntegrityChecker::class)->checkFiles()->passes())->toBeTrue();
    } finally {
        putenv('MI_FILES_ROOT');
        File::deleteDirectory($root);
    }
});

it('keeps the chain of a model intact under parallel relation records', function (): void {
    // recordRelation() changes no row of the model, so no row lock serializes the writers.
    $post = Post::query()->create(['title' => 'Shared']);

    $expected = 1 + WORKERS * WRITES_PER_WORKER;

    expect(runWorkers('relations', $post->getKey(), 'relations', $expected))->toBe([]);

    expectIntactChains($expected);

    expect($post->integrityVersions()->pluck('version')->map(fn ($v): int => (int) $v)->all())
        ->toBe(range(1, $expected));
});
