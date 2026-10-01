<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Anchoring\Anchorer;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorVerification;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\MerkleTree;
use MuellerSchmitz\ModelIntegrity\Exceptions\AnchorFailedException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;
use MuellerSchmitz\ModelIntegrity\Models\AnchorProof;
use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;

/**
 * A driver that records what it was given, and can be told to fail.
 */
class RecordingAnchor implements Anchor
{
    /** @var list<AnchorStatement> */
    public array $submitted = [];

    public bool $fails = false;

    public function submit(AnchorStatement $statement): string
    {
        if ($this->fails) {
            throw new RuntimeException('calendar unreachable');
        }

        $this->submitted[] = $statement;

        return "\x00binary proof of ".$statement->digest();
    }

    public function verify(AnchorStatement $statement, string $proof): AnchorVerification
    {
        return AnchorVerification::confirmed('ok');
    }
}

beforeEach(function (): void {
    Storage::fake('anchors');
    config([
        'model-integrity.anchors.drivers' => ['disk', 'recording'],
        'model-integrity.anchors.disk' => ['disk' => 'anchors', 'path' => 'statements'],
    ]);

    $recording = $this->recording = new RecordingAnchor;
    // extend() binds the closure to the manager, so $this cannot be used inside.
    app(AnchorManager::class)->extend('recording', fn () => $recording);

    $this->anchorer = app(Anchorer::class);
});

function createInvoices(int $count): void
{
    static $number = 0;

    foreach (range(1, $count) as $_) {
        Invoice::query()->create(['number' => 'RE-'.++$number, 'total' => '1.00']);
    }
}

it('anchors nothing without versions', function (): void {
    expect($this->anchorer->anchor()->anchor)->toBeNull()
        ->and(AnchorRecord::query()->count())->toBe(0);
});

it('anchors all versions in a first anchor', function (): void {
    createInvoices(3);

    $anchor = $this->anchorer->anchor()->anchor;
    $hashes = Version::query()->orderBy('sequence')->pluck('hash')->all();

    expect($anchor)->toBeInstanceOf(AnchorRecord::class)
        ->and($anchor->from_sequence)->toBe(1)
        ->and($anchor->to_sequence)->toBe(3)
        ->and($anchor->merkle_root)->toBe((new MerkleTree)->root($hashes))
        ->and($anchor->prev_digest)->toBeNull()
        ->and($anchor->digest)->toBe($anchor->statement()->digest())
        ->and($this->recording->submitted)->toHaveCount(1)
        ->and($this->recording->submitted[0]->digest())->toBe($anchor->digest);
});

it('stores one proof per driver, base64-encoded', function (): void {
    createInvoices(1);

    $anchor = $this->anchorer->anchor()->anchor;
    $proofs = $anchor->proofs->keyBy('driver');

    expect($proofs->keys()->sort()->values()->all())->toBe(['disk', 'recording'])
        ->and($proofs['recording']->contents())->toBe("\x00binary proof of ".$anchor->digest)
        ->and(Storage::disk('anchors')->exists($proofs['disk']->contents()))->toBeTrue();
});

it('continues the range and links the previous anchor', function (): void {
    createInvoices(2);
    $first = $this->anchorer->anchor()->anchor;
    createInvoices(3);

    $second = $this->anchorer->anchor()->anchor;

    expect($second->from_sequence)->toBe(3)
        ->and($second->to_sequence)->toBe(5)
        ->and($second->prev_digest)->toBe($first->digest);

    $head = DB::table('integrity_heads')->where('chain', 'anchors')->first();

    expect((int) $head->sequence)->toBe(5)
        ->and($head->hash)->toBe($second->digest);
});

it('anchors nothing when no version was added since the last anchor', function (): void {
    createInvoices(2);
    $this->anchorer->anchor();

    expect($this->anchorer->anchor()->anchor)->toBeNull()
        ->and(AnchorRecord::query()->count())->toBe(1);
});

it('submits only to the given drivers', function (): void {
    createInvoices(1);

    $anchor = $this->anchorer->anchor(['recording'])->anchor;

    expect($anchor->proofs->pluck('driver')->all())->toBe(['recording'])
        ->and(Storage::disk('anchors')->allFiles())->toBe([]);
});

it('keeps the anchor when some drivers fail, and reports them', function (): void {
    createInvoices(1);
    $this->recording->fails = true;

    $run = $this->anchorer->anchor();

    expect($run->anchor)->not->toBeNull()
        ->and($run->anchor->proofs->pluck('driver')->all())->toBe(['disk'])
        ->and(array_keys($run->failures))->toBe(['recording'])
        ->and($run->failures['recording']->getMessage())->toBe('calendar unreachable');
});

it('keeps nothing when every driver fails', function (): void {
    createInvoices(1);
    $this->recording->fails = true;

    expect(fn () => $this->anchorer->anchor(['recording']))->toThrow(AnchorFailedException::class, 'calendar unreachable');

    expect(AnchorRecord::query()->count())->toBe(0)
        ->and(AnchorProof::query()->count())->toBe(0)
        ->and(DB::table('integrity_heads')->where('chain', 'anchors')->value('sequence'))->toEqual(0);
});

it('refuses to anchor a chain with a sequence gap', function (): void {
    createInvoices(3);
    DB::table('integrity_versions')->where('sequence', 2)->delete();

    $this->anchorer->anchor();
})->throws(RuntimeException::class, 'model-integrity:verify');

it('rejects unknown drivers before anchoring', function (): void {
    createInvoices(1);

    expect(fn () => $this->anchorer->anchor(['nope']))->toThrow(InvalidArgumentException::class);

    expect(AnchorRecord::query()->count())->toBe(0);
});

it('refuses to create anchors through Eloquent', function (): void {
    AnchorRecord::query()->create(['anchor_format' => 1]);
})->throws(ImmutableModelException::class);
