<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatus;
use MuellerSchmitz\ModelIntegrity\Anchoring\Drivers\DiskAnchor;

beforeEach(function (): void {
    Storage::fake('anchors');
    config(['model-integrity.anchors.disk' => ['disk' => 'anchors', 'path' => 'statements']]);

    $this->driver = app(AnchorManager::class)->driver('disk');
    $this->statement = new AnchorStatement(1, 1, 3, str_repeat('a', 64), null);
});

it('is created by the anchor manager', function (): void {
    expect($this->driver)->toBeInstanceOf(DiskAnchor::class);
});

it('stores the canonical statement, so the file hash is the digest', function (): void {
    $proof = $this->driver->submit($this->statement);

    expect($proof)->toBe('statements/00000000000000000003-'.$this->statement->digest().'.json')
        ->and(Storage::disk('anchors')->get($proof))->toBe($this->statement->canonical())
        ->and(hash('sha256', (string) Storage::disk('anchors')->get($proof)))->toBe($this->statement->digest());
});

it('confirms a stored statement', function (): void {
    $proof = $this->driver->submit($this->statement);

    expect($this->driver->verify($this->statement, $proof)->status)->toBe(AnchorStatus::Confirmed);
});

it('rejects a statement that differs from the stored one', function (): void {
    $proof = $this->driver->submit($this->statement);
    $rewritten = new AnchorStatement(1, 1, 3, str_repeat('b', 64), null);

    $verification = $this->driver->verify($rewritten, $proof);

    expect($verification->status)->toBe(AnchorStatus::Invalid)
        ->and($verification->message)->toContain('not the file of this statement');
});

it('rejects a statement file whose content was changed', function (): void {
    $proof = $this->driver->submit($this->statement);
    Storage::disk('anchors')->put($proof, str_replace('"to_sequence":3', '"to_sequence":4', $this->statement->canonical()));

    $verification = $this->driver->verify($this->statement, $proof);

    expect($verification->status)->toBe(AnchorStatus::Invalid)
        ->and($verification->message)->toContain('does not match');
});

it('rejects a missing statement file', function (): void {
    $proof = $this->driver->submit($this->statement);
    Storage::disk('anchors')->delete($proof);

    $verification = $this->driver->verify($this->statement, $proof);

    expect($verification->status)->toBe(AnchorStatus::Invalid)
        ->and($verification->message)->toContain('missing');
});

it('submits the same statement twice without failing', function (): void {
    expect($this->driver->submit($this->statement))->toBe($this->driver->submit($this->statement));
});

it('refuses to overwrite a different file under the same name', function (): void {
    $proof = $this->driver->submit($this->statement);
    Storage::disk('anchors')->put($proof, 'tampered');

    $this->driver->submit($this->statement);
})->throws(RuntimeException::class);

it('lists the stored statements in sequence order', function (): void {
    $second = new AnchorStatement(1, 4, 12, str_repeat('c', 64), $this->statement->digest());
    $this->driver->submit($second);
    $this->driver->submit($this->statement);
    Storage::disk('anchors')->put('statements/unrelated.txt', 'x');

    $listed = iterator_to_array($this->driver->statements(), false);

    expect(array_map(fn ($entry) => $entry['statement']?->digest(), $listed))->toBe([$this->statement->digest(), $second->digest()]);
});

it('lists an unreadable statement file without a statement', function (): void {
    Storage::disk('anchors')->put('statements/00000000000000000003-'.str_repeat('d', 64).'.json', '{"broken"');

    $listed = iterator_to_array($this->driver->statements(), false);

    expect($listed)->toHaveCount(1)
        ->and($listed[0]['statement'])->toBeNull()
        ->and($listed[0]['location'])->toContain('statements/');
});

it('rejects a proof that points to another file, even with the right content', function (): void {
    // e.g. a copy uploaded through the application to the same disk
    $proof = $this->driver->submit($this->statement);
    Storage::disk('anchors')->put('uploads/copy.json', (string) Storage::disk('anchors')->get($proof));

    $verification = $this->driver->verify($this->statement, 'uploads/copy.json');

    expect($verification->status)->toBe(AnchorStatus::Invalid)
        ->and($verification->message)->toContain('not the file of this statement');
});

it('keeps verifying proofs under a former path after the path was changed', function (): void {
    $proof = $this->driver->submit($this->statement);
    config(['model-integrity.anchors.disk.path' => 'after-restore']);
    app(AnchorManager::class)->forgetDrivers();

    expect(app(AnchorManager::class)->driver('disk')->verify($this->statement, $proof)->status)->toBe(AnchorStatus::Confirmed);
});
