<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatus;
use MuellerSchmitz\ModelIntegrity\Anchoring\Drivers\OpenTimestampsAnchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Attestation;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Codec;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\DetachedTimestamp;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Op;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Timestamp;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Tests\Support\OpenTimestampsFake as Ots;

beforeEach(function (): void {
    Ots::install();

    $this->statement = new AnchorStatement(1, 1, 3, str_repeat('a', 64), null);
    $this->digest = hex2bin($this->statement->digest());
    $this->driver = app(AnchorManager::class)->driver('opentimestamps');
});

it('is created by the anchor manager', function (): void {
    expect($this->driver)->toBeInstanceOf(OpenTimestampsAnchor::class);
});

it('rejects calendars that are no https URLs', function (array $calendars): void {
    config(['model-integrity.anchors.opentimestamps.calendars' => $calendars]);
    app(AnchorManager::class)->forgetDrivers();

    app(AnchorManager::class)->driver('opentimestamps');
})->with([
    'plain http' => [['http://alice.example']],
])->throws(IntegrityConfigurationException::class);

it('submits the digest to every calendar and keeps one .ots proof', function (): void {

    $proof = $this->driver->submit($this->statement);
    $file = (new Codec)->decodeDetached($proof);
    $uris = array_map(fn (array $entry): ?string => $entry[0]->uri(), $file->timestamp->allAttestations());
    sort($uris);

    expect(str_starts_with($proof, Codec::MAGIC))->toBeTrue()
        ->and($file->digest)->toBe($this->digest)
        ->and($uris)->toBe([Ots::ALICE, Ots::BOB]);
});

it('keeps the proof when enough calendars answered', function (): void {
    Ots::$failing = [Ots::BOB];

    expect((new Codec)->decodeDetached($this->driver->submit($this->statement))->timestamp->allAttestations())->toHaveCount(1);
});

it('fails when fewer calendars than required answered', function (): void {
    config(['model-integrity.anchors.opentimestamps.min_calendars' => 2]);
    app(AnchorManager::class)->forgetDrivers();
    Ots::$failing = [Ots::BOB];

    app(AnchorManager::class)->driver('opentimestamps')->submit($this->statement);
})->throws(RuntimeException::class, 'bob.example');

it('reports a proof as pending until a Bitcoin attestation exists', function (): void {

    expect($this->driver->verify($this->statement, $this->driver->submit($this->statement))->status)->toBe(AnchorStatus::Pending);
});

it('confirms a proof with a Bitcoin attestation that matches its block', function (): void {
    $proof = $this->driver->submit($this->statement);
    Ots::confirm(Ots::ALICE, $this->digest, 900000, '2026-10-01 12:00:00');

    $upgraded = $this->driver->upgrade($this->statement, $proof);
    $verification = $this->driver->verify($this->statement, (string) $upgraded);

    expect($verification->status)->toBe(AnchorStatus::Confirmed)
        ->and($verification->attestedAt?->toIso8601String())->toBe('2026-10-01T12:00:00+00:00')
        ->and($verification->message)->toContain('900000');
});

it('keeps the previous proof when upgrading', function (): void {
    $proof = $this->driver->submit($this->statement);
    Ots::confirm(Ots::ALICE, $this->digest, 900000, '2026-10-01 12:00:00');

    $upgraded = $this->driver->upgrade($this->statement, $proof);
    $codec = new Codec;

    expect($codec->decodeDetached((string) $upgraded)->timestamp->contains($codec->decodeDetached($proof)->timestamp))->toBeTrue();
});

it('returns no upgrade while the calendars have none', function (): void {

    expect($this->driver->upgrade($this->statement, $this->driver->submit($this->statement)))->toBeNull();
});

it('asks only configured calendars for upgrades', function (): void {
    // A pending URI written into the database must not make the server request it.
    $timestamp = new Timestamp($this->digest);
    $timestamp->add(Op::sha256())->attest(Attestation::pending('http://169.254.169.254/latest'));
    $proof = (new Codec)->encodeDetached(new DetachedTimestamp($this->digest, $timestamp));

    expect($this->driver->upgrade($this->statement, $proof))->toBeNull();

    Http::assertNothingSent();
});

it('rejects an invented Bitcoin attestation', function (): void {
    // An attacker with database access can write any attestation into a proof.
    $timestamp = new Timestamp($this->digest);
    $timestamp->add(Op::sha256())->attest(Attestation::bitcoin(900000));
    $proof = (new Codec)->encodeDetached(new DetachedTimestamp($this->digest, $timestamp));
    Ots::block(900000, random_bytes(32), '2026-10-01 12:00:00');

    $verification = $this->driver->verify($this->statement, $proof);

    expect($verification->status)->toBe(AnchorStatus::Invalid)
        ->and($verification->message)->toContain('900000');
});

it('rejects a proof for another statement', function (): void {
    $other = new AnchorStatement(1, 1, 3, str_repeat('b', 64), null);

    expect($this->driver->verify($this->statement, $this->driver->submit($other))->status)->toBe(AnchorStatus::Invalid);
});

it('rejects a proof that is no OpenTimestamps file', function (): void {
    expect($this->driver->verify($this->statement, 'garbage')->status)->toBe(AnchorStatus::Invalid);
});

it('throws when the block source is unreachable, so the proof counts as unverifiable', function (): void {
    $proof = $this->driver->submit($this->statement);
    Ots::confirm(Ots::ALICE, $this->digest, 900000, '2026-10-01 12:00:00');
    $upgraded = (string) $this->driver->upgrade($this->statement, $proof);
    app(AnchorManager::class)->forgetDrivers();
    Ots::$blockSourceDown = true;

    app(AnchorManager::class)->driver('opentimestamps')->verify($this->statement, $upgraded);
})->throws(RuntimeException::class, 'block source');

it('does not ask the calendars again once a proof has a Bitcoin attestation', function (): void {
    $proof = $this->driver->submit($this->statement);
    Ots::confirm(Ots::ALICE, $this->digest, 900000, '2026-10-01 12:00:00');
    $upgraded = (string) $this->driver->upgrade($this->statement, $proof);
    $requests = count(Http::recorded());

    expect($this->driver->upgrade($this->statement, $upgraded))->toBeNull()
        ->and(count(Http::recorded()))->toBe($requests);
});

it('keeps the upgrade of one calendar when another one fails', function (): void {
    $proof = $this->driver->submit($this->statement);
    Ots::confirm(Ots::ALICE, $this->digest, 900000, '2026-10-01 12:00:00');
    Ots::$failing = [Ots::BOB];

    $upgraded = $this->driver->upgrade($this->statement, $proof);

    expect($upgraded)->not->toBeNull()
        ->and($this->driver->verify($this->statement, (string) $upgraded)->status)->toBe(AnchorStatus::Confirmed);
});

it('fails the upgrade when calendars fail and none had anything new', function (): void {
    $proof = $this->driver->submit($this->statement);
    Ots::$failing = [Ots::BOB];

    $this->driver->upgrade($this->statement, $proof);
})->throws(RuntimeException::class, 'bob.example');

it('verifies without calendars, but cannot submit', function (): void {
    $proof = $this->driver->submit($this->statement);
    config(['model-integrity.anchors.opentimestamps.calendars' => []]);
    app(AnchorManager::class)->forgetDrivers();
    $driver = app(AnchorManager::class)->driver('opentimestamps');

    expect($driver->verify($this->statement, $proof)->status)->toBe(AnchorStatus::Pending)
        ->and(fn () => $driver->submit($this->statement))->toThrow(IntegrityConfigurationException::class);
});

it('rejects more required calendars than configured', function (): void {
    config(['model-integrity.anchors.opentimestamps.min_calendars' => 3]);
    app(AnchorManager::class)->forgetDrivers();

    app(AnchorManager::class)->driver('opentimestamps')->submit($this->statement);
})->throws(IntegrityConfigurationException::class, 'min_calendars (3)');
