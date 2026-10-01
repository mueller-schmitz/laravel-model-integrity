<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use Symfony\Component\Process\Process;

/*
 * A verification must not report an intact database as broken just because
 * another process records versions while it runs. The writer is started
 * right after the check's first read, so every later read would see newer
 * data without a consistent view.
 */

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite serializes writers; there is no concurrent write to observe.');
    }

    $this->invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
    $this->invoice->update(['total' => '2.00']);
    $this->shared = Invoice::query()->create(['number' => 'SHARED', 'total' => '0.00']);
});

function writeDuringFirstRead(int|string $sharedId): void
{
    $triggered = false;

    DB::listen(function (QueryExecuted $query) use (&$triggered, $sharedId): void {
        if ($triggered || ! str_contains($query->sql, 'integrity_heads')) {
            return;
        }

        $triggered = true;

        $process = new Process([PHP_BINARY, __DIR__.'/worker.php', 'models', '1', '3', (string) $sharedId], timeout: 120);
        $process->mustRun();
    });
}

function messages(IntegrityChecker $checker, string $method, mixed ...$arguments): array
{
    return $checker->{$method}(...$arguments)->errors()->map(fn (IntegrityError $error): string => (string) $error)->all();
}

it('checkModel passes while another process records versions', function (): void {
    writeDuringFirstRead($this->shared->getKey());

    expect(messages(app(IntegrityChecker::class), 'checkModel', $this->shared))->toBe([]);
});

it('checkChain passes while another process records versions', function (): void {
    writeDuringFirstRead($this->shared->getKey());

    expect(messages(app(IntegrityChecker::class), 'checkChain'))->toBe([]);
});

it('checkAll passes while another process records versions', function (): void {
    writeDuringFirstRead($this->shared->getKey());

    expect(messages(app(IntegrityChecker::class), 'checkAll'))->toBe([]);
});

it('checkAll and checkAnchors pass while another process records and anchors versions', function (string $method): void {
    $root = sys_get_temp_dir().'/mi-anchors-'.bin2hex(random_bytes(4));
    config([
        'filesystems.disks.anchors' => ['driver' => 'local', 'root' => $root],
        'model-integrity.anchors' => ['drivers' => ['disk'], 'disk' => ['disk' => 'anchors', 'path' => 'statements']],
    ]);
    $sharedId = (string) $this->shared->getKey();
    $triggered = false;

    // The new anchor covers versions the check's read view does not contain.
    DB::listen(function (QueryExecuted $query) use (&$triggered, $sharedId, $root): void {
        if ($triggered || ! str_contains($query->sql, 'integrity_')) {
            return;
        }

        $triggered = true;

        foreach ([['models', '3'], ['anchor', '1']] as [$mode, $writes]) {
            (new Process([PHP_BINARY, __DIR__.'/worker.php', $mode, '1', $writes, $sharedId], env: ['MI_ANCHORS_ROOT' => $root], timeout: 120))->mustRun();
        }
    });

    try {
        expect(messages(app(IntegrityChecker::class), $method))->toBe([])
            ->and($triggered)->toBeTrue()
            ->and(File::allFiles($root))->toHaveCount(1);
    } finally {
        File::deleteDirectory($root);
    }
})->with(['checkAll', 'checkAnchors']);
