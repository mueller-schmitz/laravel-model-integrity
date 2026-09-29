<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use Symfony\Component\Process\Process;

/*
 * A caller that read inside its transaction before storing a file has a fixed
 * snapshot under REPEATABLE READ. If another process stores the same content
 * meanwhile, the caller must get that record instead of a duplicate key error.
 */

beforeEach(function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite serializes writers.');
    }

    $this->root = sys_get_temp_dir().'/mi-files-'.uniqid();
    config(['filesystems.disks.integrity' => ['driver' => 'local', 'root' => $this->root], 'model-integrity.files.disk' => 'integrity']);
});

afterEach(function (): void {
    if (isset($this->root)) {
        File::deleteDirectory($this->root);
    }
});

it('returns the record another process committed after the caller read', function (): void {
    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
    $path = tempnam(sys_get_temp_dir(), 'mi');
    file_put_contents($path, 'shared upload');

    $file = DB::transaction(function () use ($path) {
        // Fixes the snapshot of this transaction on MySQL/MariaDB.
        Invoice::query()->first();

        (new Process([PHP_BINARY, __DIR__.'/worker.php', 'store-one', '1', '1', 'shared upload'], env: ['MI_FILES_ROOT' => $this->root], timeout: 60))->mustRun();

        return IntegrityFiles::store($path);
    });

    expect($file->sha256)->toBe(hash('sha256', 'shared upload'))
        ->and(DB::table('integrity_files')->count())->toBe(1);
});
