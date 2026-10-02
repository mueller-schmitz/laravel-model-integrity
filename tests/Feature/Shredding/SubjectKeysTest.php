<?php

declare(strict_types=1);

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Exceptions\ShreddedSubjectException;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectKeys;

beforeEach(function (): void {
    $this->keys = app(SubjectKeys::class);
});

it('creates one key per subject and returns it again', function (): void {
    $first = $this->keys->keyFor('customer:1');
    $again = $this->keys->keyFor('customer:1');
    $other = $this->keys->keyFor('customer:2');

    expect($again)->toEqual($first)
        ->and(strlen($first->key))->toBe(32)
        ->and($other->id)->not->toBe($first->id)
        ->and($other->key)->not->toBe($first->key)
        ->and(DB::table('integrity_subject_keys')->count())->toBe(2);
});

it('stores keys only wrapped with the application key', function (): void {
    $key = $this->keys->keyFor('customer:1');
    $stored = (string) DB::table('integrity_subject_keys')->where('id', $key->id)->value('key');

    expect($stored)->not->toContain(base64_encode($key->key))
        ->and(base64_decode(Crypt::decryptString($stored)))->toBe($key->key);
});

it('finds keys by id from a fresh instance', function (): void {
    $key = $this->keys->keyFor('customer:1');

    expect(app()->make(SubjectKeys::class)->find($key->id))->toBe($key->key)
        ->and(app()->make(SubjectKeys::class)->find('01J00000000000000000000000'))->toBeNull();
});

it('shreds a key for good', function (): void {
    $key = $this->keys->keyFor('customer:1');

    expect($this->keys->shred('customer:1'))->toBe($key->id)
        ->and($this->keys->find($key->id))->toBeNull()
        ->and($this->keys->isShredded('customer:1'))->toBeTrue()
        ->and(DB::table('integrity_subject_keys')->where('id', $key->id)->value('key'))->toBeNull()
        ->and(DB::table('integrity_subject_keys')->where('id', $key->id)->value('shredded_at'))->not->toBeNull()
        ->and(fn () => $this->keys->keyFor('customer:1'))->toThrow(ShreddedSubjectException::class);
});

it('forgets a shredded key in the same instance', function (): void {
    $key = $this->keys->keyFor('customer:1');
    $this->keys->find($key->id);

    $this->keys->shred('customer:1');

    expect($this->keys->find($key->id))->toBeNull();
});

it('keeps a tombstone when shredding a subject that never had a key', function (): void {
    expect($this->keys->shred('customer:9'))->toBeNull()
        ->and($this->keys->isShredded('customer:9'))->toBeTrue()
        ->and(fn () => $this->keys->keyFor('customer:9'))->toThrow(ShreddedSubjectException::class);
});

it('shreds twice without failing', function (): void {
    $this->keys->keyFor('customer:1');
    $this->keys->shred('customer:1');

    expect($this->keys->shred('customer:1'))->toBeNull();
});

it('unwraps keys after the application key was rotated', function (): void {
    $key = $this->keys->keyFor('customer:1');
    $old = config('app.key');

    config(['app.key' => 'base64:'.base64_encode(Encrypter::generateKey('aes-256-cbc')), 'app.previous_keys' => [$old]]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstances();

    expect(app()->make(SubjectKeys::class)->find($key->id))->toBe($key->key);
});

it('hashes long subject names to fit the column', function (): void {
    $subject = 'App\\Models\\'.str_repeat('VeryLongModelName', 20).':1';

    $this->keys->keyFor($subject);

    expect(strlen((string) DB::table('integrity_subject_keys')->value('subject')))->toBeLessThanOrEqual(191)
        ->and($this->keys->keyFor($subject)->id)->toBe(DB::table('integrity_subject_keys')->value('id'));
});
