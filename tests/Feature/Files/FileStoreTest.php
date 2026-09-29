<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Events\IntegrityViolationDetected;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\Files\FileStore;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Contract;

beforeEach(function (): void {
    Storage::fake('integrity');
    config(['model-integrity.files.disk' => 'integrity', 'model-integrity.files.path' => 'files']);

    $this->store = app(FileStore::class);
});

function tempFileWith(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'mi');
    file_put_contents($path, $contents);

    return $path;
}

it('stores a file under its hash', function (): void {
    $file = $this->store->store(tempFileWith('hello world'));
    $sha = hash('sha256', 'hello world');

    expect($file)->toBeInstanceOf(StoredFile::class)
        ->sha256->toBe($sha)
        ->disk->toBe('integrity')
        ->path->toBe('files/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha)
        ->size->toBe(11)
        ->mime->toBe('text/plain');

    Storage::disk('integrity')->assertExists($file->path);
    expect($file->contents())->toBe('hello world');
});

it('accepts uploaded files, file objects, paths and streams', function (): void {
    $path = tempFileWith('%PDF-1.4 sample');
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'from a stream');
    rewind($stream);

    $fromUpload = $this->store->store(new UploadedFile($path, 'invoice.pdf', null, null, true));
    $fromObject = $this->store->store(new SplFileInfo($path));
    $fromStream = $this->store->store($stream);
    fclose($stream);

    expect($fromUpload->sha256)->toBe(hash('sha256', '%PDF-1.4 sample'))
        ->and($fromObject->is($fromUpload))->toBeTrue()
        ->and($fromStream->contents())->toBe('from a stream');
});

it('stores identical content once', function (): void {
    $first = $this->store->store(tempFileWith('same'));
    $second = $this->store->store(tempFileWith('same'));

    expect($second->is($first))->toBeTrue()
        ->and(StoredFile::query()->count())->toBe(1)
        ->and(Version::query()->where('versionable_type', (new StoredFile)->getMorphClass())->count())->toBe(1);
});

it('records a version for each stored file in the global chain', function (): void {
    $file = $this->store->store(tempFileWith('chained'));

    $version = $file->integrityVersions()->sole();

    expect($version->event)->toBe('created')
        ->and($version->snapshot)->toMatchArray(['sha256' => $file->sha256, 'size' => 7, 'disk' => 'integrity'])
        ->and($file->verifyIntegrity()->passes())->toBeTrue();
});

it('keeps an existing file with the right content', function (): void {
    $sha = hash('sha256', 'original');
    $path = 'files/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
    Storage::disk('integrity')->put($path, 'original');
    $modified = Storage::disk('integrity')->lastModified($path);

    $this->store->store(tempFileWith('original'));

    expect(Storage::disk('integrity')->get($path))->toBe('original')
        ->and(Storage::disk('integrity')->lastModified($path))->toBe($modified);
});

it('never attests an existing file with other content, and keeps it as evidence', function (): void {
    Event::fake([IntegrityViolationDetected::class]);
    $sha = hash('sha256', 'original');
    $path = 'files/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
    // e.g. a partial file left by an aborted write, or a tampered one
    Storage::disk('integrity')->put($path, 'origi');

    $file = $this->store->store(tempFileWith('original'));

    $evidence = collect(Storage::disk('integrity')->files(dirname($path)))->first(fn (string $f): bool => str_contains($f, '.corrupt-'));

    expect($file->contents())->toBe('original')
        ->and($evidence)->not->toBeNull()
        ->and(Storage::disk('integrity')->get($evidence))->toBe('origi');

    Event::assertDispatched(IntegrityViolationDetected::class, fn (IntegrityViolationDetected $e): bool => $e->result->errors()->sole()->type->value === 'file_mismatch');
});

it('writes files atomically, without temporary files left behind', function (): void {
    $file = $this->store->store(tempFileWith('atomic'));

    expect(Storage::disk('integrity')->files(dirname($file->path)))->toBe([$file->path]);
});

it('restores a recorded file missing on the disk when the same content is stored again, and reports it', function (): void {
    $file = $this->store->store(tempFileWith('heal me'));
    Storage::disk('integrity')->delete($file->path);
    Event::fake([IntegrityViolationDetected::class]);

    $again = $this->store->store(tempFileWith('heal me'));

    expect($again->is($file))->toBeTrue()
        ->and($again->contents())->toBe('heal me');

    Event::assertDispatched(IntegrityViolationDetected::class, fn (IntegrityViolationDetected $e): bool => str_contains($e->result->errors()->sole()->message, 'was missing'));
});

it('reports nothing when a file is stored for the first time or again', function (): void {
    Event::fake([IntegrityViolationDetected::class]);

    $this->store->store(tempFileWith('quiet'));
    $this->store->store(tempFileWith('quiet'));

    Event::assertNotDispatched(IntegrityViolationDetected::class);
});

it('does not treat an existing file it cannot read as corrupt', function (): void {
    Event::fake([IntegrityViolationDetected::class]);
    $file = $this->store->store(tempFileWith('unreadable'));
    $disk = Mockery::mock(Storage::disk('integrity'))->makePartial();
    $disk->shouldReceive('readStream')->andReturnNull();
    Storage::set('integrity', $disk);

    expect(fn () => $this->store->store(tempFileWith('unreadable')))->toThrow(RuntimeException::class, 'Cannot read');

    expect(Storage::disk('integrity')->files(dirname($file->path)))->toBe([$file->path]);
    Event::assertNotDispatched(IntegrityViolationDetected::class);
});

it('keeps a corrupt file in place when it cannot be moved away as evidence', function (): void {
    Event::fake([IntegrityViolationDetected::class]);
    $sha = hash('sha256', 'original');
    $path = 'files/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
    Storage::disk('integrity')->put($path, 'tampered');
    $disk = Mockery::mock(Storage::disk('integrity'))->makePartial();
    $disk->shouldReceive('move')->with($path, Mockery::pattern('/\.corrupt-/'))->andReturnFalse();
    Storage::set('integrity', $disk);

    expect(fn () => $this->store->store(tempFileWith('original')))->toThrow(RuntimeException::class, 'evidence');

    expect(Storage::disk('integrity')->get($path))->toBe('tampered')
        ->and(StoredFile::query()->count())->toBe(0);
    Event::assertNotDispatched(IntegrityViolationDetected::class);
});

it('removes the temporary file when writing fails', function (): void {
    $disk = Mockery::mock(Storage::disk('integrity'))->makePartial();
    $disk->shouldReceive('writeStream')->andReturnUsing(function (string $path): bool {
        Storage::disk('integrity')->put($path, 'partial');

        return false;
    });
    Storage::set('integrity', $disk);

    expect(fn () => $this->store->store(tempFileWith('broken write')))->toThrow(RuntimeException::class, 'Cannot write');

    expect(Storage::disk('integrity')->allFiles())->toBe([]);
});

it('reads a stream from its start', function (): void {
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'written, not rewound');

    $file = $this->store->store($stream);
    fclose($stream);

    expect($file->contents())->toBe('written, not rewound');
});

it('writes the file outside the transaction that locks the chain head', function (): void {
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (str_contains($query->sql, 'integrity_heads') && str_contains(strtolower($query->sql), 'for update')) {
            $writes[] = Storage::disk('integrity')->allFiles();
        }
    });

    $file = $this->store->store(tempFileWith('outside'));

    // When the head is locked, the file is already on the disk.
    expect($writes)->not->toBeEmpty()
        ->and($writes[0])->toContain($file->path);
})->skip(fn () => DB::connection()->getDriverName() === 'sqlite', 'SQLite has no FOR UPDATE');

it('exposes only the hash when serializing a model', function (): void {
    $file = $this->store->store(tempFileWith('private path'));
    $contract = Contract::query()->create(['title' => 'Lease', 'document' => $file]);

    expect($contract->fresh()->toArray()['document'])->toBe($file->sha256);
});

it('works with an enforced morph map', function (): void {
    Relation::requireMorphMap();

    try {
        $file = $this->store->store(tempFileWith('strict app'));
    } finally {
        Relation::requireMorphMap(false);
    }

    expect($file->getMorphClass())->toBe('model-integrity.file')
        ->and($file->integrityVersions()->sole()->versionable_type)->toBe('model-integrity.file');
});

it('does not put the upload time into the snapshot, which depends on the app timezone', function (): void {
    expect($this->store->store(tempFileWith('timeless'))->integrityVersions()->sole()->snapshot)->not->toHaveKey('created_at');
});

it('uses a given mime type', function (): void {
    expect($this->store->store(tempFileWith('a,b'), 'text/csv')->mime)->toBe('text/csv');
});

it('keeps nothing in the database when the surrounding transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            $this->store->store(tempFileWith('rolled back'));

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(StoredFile::query()->count())->toBe(0);
});

it('rejects missing paths and unsupported input', function (mixed $input): void {
    $this->store->store($input);
})->with([
    'missing path' => ['/does/not/exist'],
    'integer' => [42],
])->throws(InvalidArgumentException::class);

it('finds files by hash through the facade', function (): void {
    $file = IntegrityFiles::store(tempFileWith('find me'));

    expect(IntegrityFiles::find($file->sha256)?->is($file))->toBeTrue()
        ->and(IntegrityFiles::find(str_repeat('0', 64)))->toBeNull();
});

it('refuses to change or delete a stored file record', function (): void {
    $file = $this->store->store(tempFileWith('immutable'));

    expect(fn () => $file->update(['mime' => 'x/y']))->toThrow(ImmutableModelException::class)
        ->and(fn () => $file->delete())->toThrow(ImmutableModelException::class);
});
