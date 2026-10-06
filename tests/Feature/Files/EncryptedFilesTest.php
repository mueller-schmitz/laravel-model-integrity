<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Exceptions\MissingSubjectKeyException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ShreddedSubjectException;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\Facades\IntegritySubjects;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectKey;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectKeys;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectName;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Contract;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Customer;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;

const PERSONAL_CONTENT = 'Contract of Ada Lovelace';

beforeEach(function (): void {
    Storage::fake('integrity');
    config(['model-integrity.files.disk' => 'integrity']);

    // Only the anonymized value: the tests are about the files of the subject.
    $this->customer = Customer::query()->create(['number' => 'C-1', 'name' => 'deleted']);
    $this->checker = app(IntegrityChecker::class);
});

function personalFile(string $contents = PERSONAL_CONTENT): string
{
    $path = tempnam(sys_get_temp_dir(), 'mi');
    file_put_contents($path, $contents);

    return $path;
}

/**
 * @return list<string>
 */
function encryptedFileErrors(string $type): array
{
    return app(IntegrityChecker::class)->checkAll()->errors()
        ->filter(fn (IntegrityError $error): bool => $error->type->value === $type)
        ->map(fn (IntegrityError $error): string => $error->message)
        ->values()
        ->all();
}

it('stores a file encrypted with the key of its data subject', function (): void {
    $file = IntegrityFiles::store(personalFile(), subject: $this->customer);
    $stored = (string) Storage::disk('integrity')->get($file->path);

    expect($file->isEncrypted())->toBeTrue()
        ->and($file->isShredded())->toBeFalse()
        ->and($file->key_id)->toBe(app(SubjectKeys::class)->keyIdFor(SubjectName::of($this->customer)))
        ->and($file->mime)->toBe('text/plain')
        ->and($stored)->toStartWith('MIFE')->not->toContain('Lovelace')
        ->and($file->sha256)->toBe(hash('sha256', $stored))
        ->and($file->size)->toBe(strlen($stored))
        ->and($file->path)->toEndWith('/'.$file->sha256);
});

it('reads an encrypted file decrypted', function (): void {
    $file = IntegrityFiles::store(personalFile(), subject: $this->customer)->fresh();
    $stream = $file->readStream();

    expect(stream_get_contents($stream))->toBe(PERSONAL_CONTENT)
        ->and($file->contents())->toBe(PERSONAL_CONTENT);
});

it('reads large and empty encrypted files', function (string $contents): void {
    $file = IntegrityFiles::store(personalFile($contents), subject: $this->customer);

    expect($file->contents())->toBe($contents);
})->with([
    'empty' => [''],
    'several chunks' => [str_repeat('0123456789', 20000)],
]);

it('accepts the subject as a name and encrypts streams', function (): void {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, PERSONAL_CONTENT);

    $file = IntegrityFiles::store($stream, subject: SubjectName::of($this->customer));
    fclose($stream);

    expect($file->key_id)->toBe(app(SubjectKeys::class)->keyIdFor(SubjectName::of($this->customer)))
        ->and($file->contents())->toBe(PERSONAL_CONTENT);
});

it('does not deduplicate encrypted files', function (): void {
    $first = IntegrityFiles::store(personalFile(), subject: $this->customer);
    $second = IntegrityFiles::store(personalFile(), subject: $this->customer);
    $plain = IntegrityFiles::store(personalFile());

    expect($second->sha256)->not->toBe($first->sha256)
        ->and($plain->sha256)->toBe(hash('sha256', PERSONAL_CONTENT))
        ->and($plain->isEncrypted())->toBeFalse()
        ->and(StoredFile::query()->count())->toBe(3);
});

it('hashes the key of an encrypted file, and leaves it out for other files', function (): void {
    $encrypted = IntegrityFiles::store(personalFile(), subject: $this->customer);
    $plain = IntegrityFiles::store(personalFile());

    expect($encrypted->integrityVersions()->sole()->snapshot)->toMatchArray(['key_id' => $encrypted->key_id])
        ->and(array_keys($plain->integrityVersions()->sole()->snapshot))->toBe(['disk', 'id', 'mime', 'path', 'sha256', 'size'])
        ->and($this->checker->checkAll()->passes())->toBeTrue();
});

it('keeps files recorded before the key column existed valid', function (): void {
    $plain = IntegrityFiles::store(personalFile());

    // The snapshot is what 0.4 recorded, and no new baseline is needed.
    $this->artisan('model-integrity:snapshot', ['--model' => StoredFile::class])->assertSuccessful();

    expect(Version::query()->where('versionable_type', StoredFile::MORPH_ALIAS)->count())->toBe(1)
        ->and($this->checker->checkModel($plain)->passes())->toBeTrue();
});

it('records no new baseline for encrypted files', function (): void {
    IntegrityFiles::store(personalFile(), subject: $this->customer);

    $this->artisan('model-integrity:snapshot', ['--all' => true])->assertSuccessful();

    expect(Version::query()->where('versionable_type', StoredFile::MORPH_ALIAS)->count())->toBe(1);
});

it('refuses to store a file for a shredded subject', function (): void {
    IntegritySubjects::shred($this->customer);

    try {
        IntegrityFiles::store(personalFile(), subject: $this->customer);
    } finally {
        expect(Storage::disk('integrity')->allFiles())->toBe([])
            ->and(StoredFile::query()->count())->toBe(0);
    }
})->throws(ShreddedSubjectException::class, 'was shredded');

it('cannot read a file once the key of its subject was shredded', function (string $method): void {
    $file = IntegrityFiles::store(personalFile(), subject: $this->customer);

    IntegritySubjects::shred($this->customer, 'Erasure request');

    expect($file->isShredded())->toBeTrue();

    $file->{$method}();
})->with(['readStream', 'contents'])->throws(ShreddedSubjectException::class, 'cannot be read any more');

it('keeps chain and files valid after shredding', function (): void {
    $file = IntegrityFiles::store(personalFile(), subject: $this->customer);
    $contract = Contract::query()->create(['title' => 'Lease', 'document' => $file]);

    expect($contract->fresh()->document->contents())->toBe(PERSONAL_CONTENT);

    IntegritySubjects::shred($this->customer);

    expect($this->checker->checkAll()->errors()->map(fn (IntegrityError $error): string => (string) $error)->all())->toBe([])
        ->and($this->checker->checkFiles()->passes())->toBeTrue()
        ->and($this->checker->checkModel($contract)->passes())->toBeTrue()
        ->and(Storage::disk('integrity')->exists($file->path))->toBeTrue();
});

it('reports a key that was removed without shredding', function (): void {
    $file = IntegrityFiles::store(personalFile(), subject: $this->customer);

    DB::table('integrity_subject_keys')->where('id', $file->key_id)->update(['key' => null]);
    app()->forgetScopedInstances();

    expect($file->isShredded())->toBeFalse();

    $file->contents();
})->throws(MissingSubjectKeyException::class, 'removed without shredding');

it('does not decrypt a file that was changed on the disk', function (): void {
    $file = IntegrityFiles::store(personalFile(), subject: $this->customer);
    $stored = (string) Storage::disk('integrity')->get($file->path);
    $stored[60] = $stored[60] === 'a' ? 'b' : 'a';
    Storage::disk('integrity')->put($file->path, $stored);

    expect($this->checker->checkFiles()->errors()->sole()->message)->toContain($file->sha256);

    $file->contents();
})->throws(DecryptException::class, 'cannot be decrypted');

it('does not decrypt a file whose key was swapped', function (): void {
    $file = IntegrityFiles::store(personalFile(), subject: $this->customer);
    $other = IntegrityFiles::store(personalFile(), subject: Customer::query()->create(['number' => 'C-2', 'name' => 'deleted']));

    DB::table('integrity_files')->where('id', $file->id)->update(['key_id' => $other->key_id]);

    expect(encryptedFileErrors('state_drift'))->toHaveCount(1)
        ->and(encryptedFileErrors('state_drift')[0])->toContain('[key_id]');

    $file->fresh()->contents();
})->throws(DecryptException::class, 'cannot be decrypted');

it('detects a key removed from or added to a file record', function (bool $encrypted): void {
    $file = IntegrityFiles::store(personalFile(), subject: $encrypted ? $this->customer : null);
    $other = IntegrityFiles::store(personalFile(), subject: $this->customer);

    DB::table('integrity_files')->where('id', $file->id)->update(['key_id' => $encrypted ? null : $other->key_id]);

    $errors = encryptedFileErrors('state_drift');

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('[key_id]');
})->with(['removed' => [true], 'added' => [false]]);

it('leaves no temporary files behind', function (): void {
    $before = glob(sys_get_temp_dir().'/mod*') ?: [];
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, PERSONAL_CONTENT);

    IntegrityFiles::store($stream, subject: $this->customer);
    fclose($stream);

    expect(array_diff(glob(sys_get_temp_dir().'/mod*') ?: [], $before))->toBe([])
        ->and(collect(Storage::disk('integrity')->allFiles())->filter(fn (string $path): bool => str_contains($path, '.tmp-'))->all())->toBe([]);
});

it('leaves no temporary files behind when storing fails', function (): void {
    $before = glob(sys_get_temp_dir().'/mod*') ?: [];
    $disk = Mockery::mock(Storage::disk('integrity'))->makePartial();
    $disk->shouldReceive('writeStream')->andReturn(false);
    Storage::set('integrity', $disk);
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, PERSONAL_CONTENT);

    expect(fn () => IntegrityFiles::store($stream, subject: $this->customer))->toThrow(RuntimeException::class, 'Cannot write');
    fclose($stream);

    expect(array_diff(glob(sys_get_temp_dir().'/mod*') ?: [], $before))->toBe([])
        ->and(StoredFile::query()->count())->toBe(0);
});

it('rejects a subject name without a type', function (string $subject): void {
    try {
        IntegrityFiles::store(personalFile(), subject: $subject);
    } finally {
        // No key for a subject that shredding the person would never reach.
        expect(DB::table('integrity_subject_keys')->where('subject', $subject)->exists())->toBeFalse();
    }
})->with(['', '5', 'customer:', ':5'])->throws(InvalidArgumentException::class, 'must be a model or its');

it('records no file for a subject that was shredded while the file was encrypted', function (): void {
    $name = SubjectName::of($this->customer);
    $stale = app(SubjectKeys::class)->keyFor($name);

    IntegritySubjects::shred($this->customer);

    // A store that read the key before the shredding was committed.
    app()->instance(SubjectKeys::class, new class($stale) extends SubjectKeys
    {
        public function __construct(private readonly SubjectKey $stale) {}

        public function keyFor(string $subject): SubjectKey
        {
            return $this->stale;
        }
    });

    try {
        IntegrityFiles::store(personalFile(), subject: $this->customer);
    } finally {
        expect(StoredFile::query()->count())->toBe(0);
    }
})->throws(ShreddedSubjectException::class, 'was shredded');

it('cannot read a file with a key cached before the shredding', function (): void {
    $file = IntegrityFiles::store(personalFile(), subject: $this->customer);

    // The facade keeps its instance while the keys are bound per request or job.
    expect(IntegritySubjects::isShredded($this->customer))->toBeFalse();
    app()->forgetScopedInstances();

    expect($file->contents())->toBe(PERSONAL_CONTENT);

    IntegritySubjects::shred($this->customer);

    $file->contents();
})->throws(ShreddedSubjectException::class, 'cannot be read any more');

it('reports a file that does not open with the key of its subject', function (): void {
    // Subjects without recorded attributes: nothing else is encrypted with their keys.
    $file = IntegrityFiles::store(personalFile(), subject: 'person:1');
    $other = IntegrityFiles::store(personalFile(), subject: 'person:2');

    // The key itself is in no snapshot: only opening the file shows the swap.
    DB::table('integrity_subject_keys')->where('id', $file->key_id)
        ->update(['key' => DB::table('integrity_subject_keys')->where('id', $other->key_id)->value('key')]);
    app()->forgetScopedInstances();

    $errors = $this->checker->checkFiles()->errors();

    expect($this->checker->checkAll()->passes())->toBeTrue()
        ->and($this->checker->checkFiles(contents: false)->passes())->toBeTrue()
        ->and($errors)->toHaveCount(1)
        ->and($errors->sole()->type->value)->toBe('file_mismatch')
        ->and($errors->sole()->message)->toContain($file->sha256)->toContain('cannot be decrypted with the key of its data subject');
});

it('reports a file whose key was removed without shredding', function (): void {
    $file = IntegrityFiles::store(personalFile(), subject: $this->customer);

    DB::table('integrity_subject_keys')->where('id', $file->key_id)->delete();
    app()->forgetScopedInstances();

    expect($this->checker->checkFiles()->errors()->sole()->message)->toContain('removed without shredding');
});
