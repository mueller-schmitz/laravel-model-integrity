<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\Files\FileStore;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Models\Version;

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

it('never overwrites an existing file on the disk', function (): void {
    $sha = hash('sha256', 'original');
    $path = 'files/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2).'/'.$sha;
    Storage::disk('integrity')->put($path, 'tampered');

    $this->store->store(tempFileWith('original'));

    // The existing file is kept; the verification reports its content (checkFiles).
    expect(Storage::disk('integrity')->get($path))->toBe('tampered');
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
