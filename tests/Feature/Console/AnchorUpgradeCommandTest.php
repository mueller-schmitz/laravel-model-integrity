<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Anchoring\Anchorer;
use MuellerSchmitz\ModelIntegrity\Models\AnchorProof;
use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Support\OpenTimestampsFake as Ots;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;

beforeEach(function (): void {
    Ots::install();
    Storage::fake('anchors');
    config([
        'model-integrity.anchors.drivers' => ['disk', 'opentimestamps'],
        'model-integrity.anchors.disk' => ['disk' => 'anchors', 'path' => 'statements'],
    ]);

    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
    $this->anchor = app(Anchorer::class)->anchor()->anchor;
    $this->digest = hex2bin($this->anchor->digest);
});

it('reports when no proof could be upgraded yet', function (): void {
    $this->artisan('model-integrity:anchor-upgrade')
        ->expectsOutputToContain('No proof was upgraded')
        ->assertExitCode(0);

    expect(AnchorProof::query()->count())->toBe(2);
});

it('adds the upgraded proof as a new row and keeps the previous one', function (): void {
    Ots::confirm(Ots::ALICE, $this->digest, 900000, CarbonImmutable::now('UTC')->toDateTimeString());

    $this->artisan('model-integrity:anchor-upgrade')
        ->expectsOutputToContain('Upgraded 1 proof')
        ->assertExitCode(0);

    $proofs = AnchorProof::query()->where('driver', 'opentimestamps')->orderBy('id')->get();

    expect($proofs)->toHaveCount(2)
        ->and(app(IntegrityChecker::class)->checkAnchors()->errors()->pluck('message')->all())->toBe([]);

    $this->artisan('model-integrity:anchor-upgrade')->expectsOutputToContain('No proof was upgraded');
});

it('continues with other anchors when a proof cannot be upgraded, and fails', function (): void {
    Invoice::query()->create(['number' => 'RE-2', 'total' => '2.00']);
    $second = app(Anchorer::class)->anchor()->anchor;
    DB::table('integrity_anchor_proofs')->where('anchor_id', $this->anchor->id)->where('driver', 'opentimestamps')->update(['proof' => base64_encode('garbage')]);
    Ots::confirm(Ots::ALICE, hex2bin($second->digest), 900000, CarbonImmutable::now('UTC')->toDateTimeString());

    $this->artisan('model-integrity:anchor-upgrade')
        ->expectsOutputToContain("anchor #{$this->anchor->id}")
        ->expectsOutputToContain('Upgraded 1 proof')
        ->assertExitCode(1);

    expect(AnchorRecord::query()->find($second->id)->proofs->where('driver', 'opentimestamps'))->toHaveCount(2);
});
