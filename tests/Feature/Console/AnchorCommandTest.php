<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorVerification;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;

beforeEach(function (): void {
    Storage::fake('anchors');
    config([
        'model-integrity.anchors.drivers' => ['disk'],
        'model-integrity.anchors.disk' => ['disk' => 'anchors', 'path' => 'statements'],
    ]);

    app(AnchorManager::class)->extend('broken', fn () => new class implements Anchor
    {
        public function submit(AnchorStatement $statement): string
        {
            throw new RuntimeException('service down');
        }

        public function verify(AnchorStatement $statement, string $proof): AnchorVerification
        {
            return AnchorVerification::invalid('never');
        }
    });
});

it('reports when there is nothing to anchor', function (): void {
    $this->artisan('model-integrity:anchor')
        ->expectsOutputToContain('Nothing to anchor')
        ->assertExitCode(0);
});

it('anchors new versions and names range, digest and drivers', function (): void {
    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
    Invoice::query()->create(['number' => 'RE-2', 'total' => '2.00']);

    $this->artisan('model-integrity:anchor')
        ->expectsOutputToContain('Anchored sequences 1 to 2')
        ->assertExitCode(0);

    $anchor = AnchorRecord::query()->sole();

    $this->artisan('model-integrity:anchor')->expectsOutputToContain('Nothing to anchor');

    expect($anchor->proofs->pluck('driver')->all())->toBe(['disk']);
});

it('fails, but keeps the anchor, when one driver fails', function (): void {
    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);

    $this->artisan('model-integrity:anchor', ['--driver' => ['disk', 'broken']])
        ->expectsOutputToContain('[broken] service down')
        ->assertExitCode(1);

    expect(AnchorRecord::query()->count())->toBe(1);
});

it('fails without an anchor when every driver fails', function (): void {
    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);

    $this->artisan('model-integrity:anchor', ['--driver' => ['broken']])
        ->expectsOutputToContain('service down')
        ->assertExitCode(1);

    expect(AnchorRecord::query()->count())->toBe(0);
});

it('rejects an unknown driver', function (): void {
    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);

    $this->artisan('model-integrity:anchor', ['--driver' => ['nope']])
        ->expectsOutputToContain('nope')
        ->assertExitCode(2);
});
