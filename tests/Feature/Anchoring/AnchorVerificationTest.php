<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Anchoring\Anchorer;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorVerification;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\MerkleTree;
use MuellerSchmitz\ModelIntegrity\Events\IntegrityViolationDetected;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\ChainName;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

beforeEach(function (): void {
    Storage::fake('anchors');
    config([
        'model-integrity.anchors.drivers' => ['disk'],
        'model-integrity.anchors.disk' => ['disk' => 'anchors', 'path' => 'statements'],
    ]);

    app(AnchorManager::class)->extend('pending', fn () => new class implements Anchor
    {
        public function submit(AnchorStatement $statement): string
        {
            return 'pending proof';
        }

        public function verify(AnchorStatement $statement, string $proof): AnchorVerification
        {
            return AnchorVerification::pending('waiting for a block');
        }
    });

    $this->checker = app(IntegrityChecker::class);
    $this->anchorer = app(Anchorer::class);

    // Two anchors over five versions: 1-3 and 4-5.
    $this->invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
    $this->invoice->update(['total' => '2.00']);
    Invoice::query()->create(['number' => 'RE-2', 'total' => '3.00']);
    $this->anchorer->anchor();
    Invoice::query()->create(['number' => 'RE-3', 'total' => '4.00']);
    Invoice::query()->create(['number' => 'RE-4', 'total' => '5.00']);
    $this->anchorer->anchor();

    // Ids, not positions: MySQL does not reset AUTO_INCREMENT when the test transaction rolls back.
    [$this->first, $this->second] = DB::table('integrity_anchors')->orderBy('id')->pluck('id')->all();
});

/**
 * @return list<string> e.g. ['anchor_mismatch']
 */
function anchorViolations(IntegrityResult $result): array
{
    return $result->errors()->map(fn ($error): string => $error->type->value)->unique()->values()->all();
}

/**
 * What an attacker with full database access does: change a version, then
 * recompute every hash, link and head so that the chain is consistent again.
 */
function rewriteChain(Closure $change): void
{
    $hasher = app(Hasher::class);
    $modelHashes = [];
    $globalHash = null;

    foreach (Version::query()->orderBy('sequence')->get() as $version) {
        $envelope = $change($version->toEnvelope());
        $chain = ChainName::for($envelope['versionable_type'], $envelope['versionable_id']);
        $envelope['prev_hash'] = $modelHashes[$chain] ?? null;
        $envelope['global_prev_hash'] = $globalHash;
        $hash = $hasher->hash($envelope);

        DB::table('integrity_versions')->where('id', $version->id)->update([
            'snapshot' => app(CanonicalSerializer::class)->encode($envelope['snapshot']),
            'prev_hash' => $envelope['prev_hash'],
            'global_prev_hash' => $globalHash,
            'hash' => $hash,
        ]);

        $modelHashes[$chain] = $globalHash = $hash;
        DB::table('integrity_heads')->where('chain', $chain)->update(['hash' => $hash]);
    }

    DB::table('integrity_heads')->where('chain', 'global')->update(['hash' => $globalHash]);
}

function forgeFirstTotal(array $envelope): array
{
    if ($envelope['sequence'] === 1) {
        $envelope['snapshot']['total'] = '1000.00';
    }

    return $envelope;
}

it('passes for intact anchors', function (): void {
    $result = $this->checker->checkAnchors();

    expect($result->passes())->toBeTrue()
        ->and($result->checkedVersions())->toBe(2)
        ->and($this->checker->checkAll()->passes())->toBeTrue();
});

it('passes without anchors', function (): void {
    DB::table('integrity_anchor_proofs')->delete();
    DB::table('integrity_anchors')->delete();
    DB::table('integrity_heads')->where('chain', 'anchors')->update(['sequence' => 0, 'hash' => null]);
    Storage::disk('anchors')->deleteDirectory('statements');

    expect($this->checker->checkAnchors()->passes())->toBeTrue();
});

it('detects a rewritten chain although every hash and link is consistent', function (): void {
    rewriteChain(forgeFirstTotal(...));

    // The chain itself cannot tell; only the anchors can.
    expect($this->checker->checkChain()->passes())->toBeTrue()
        ->and(anchorViolations($this->checker->checkAnchors()))->toBe(['anchor_mismatch']);
});

it('detects a rewritten chain whose anchors were recomputed as well', function (): void {
    rewriteChain(forgeFirstTotal(...));

    // The attacker recomputes the anchor rows, but cannot change the anchor disk.
    $prev = null;
    foreach (DB::table('integrity_anchors')->orderBy('id')->get() as $row) {
        $hashes = Version::query()->whereBetween('sequence', [$row->from_sequence, $row->to_sequence])->orderBy('sequence')->pluck('hash');
        $statement = new AnchorStatement(1, (int) $row->from_sequence, (int) $row->to_sequence, (new MerkleTree)->root($hashes), $prev);
        DB::table('integrity_anchors')->where('id', $row->id)->update(['merkle_root' => $statement->merkleRoot, 'prev_digest' => $prev, 'digest' => $statement->digest()]);
        $prev = $statement->digest();
    }
    DB::table('integrity_heads')->where('chain', 'anchors')->update(['hash' => $prev]);

    $result = $this->checker->checkAnchors();

    expect(anchorViolations($result))->toBe(['anchor_mismatch'])
        ->and($result->errors()->pluck('message')->implode("\n"))->toContain('is not the file of this statement');
});

it('detects a rewritten chain whose anchors were deleted', function (): void {
    rewriteChain(forgeFirstTotal(...));
    DB::table('integrity_anchor_proofs')->delete();
    DB::table('integrity_anchors')->delete();
    DB::table('integrity_heads')->where('chain', 'anchors')->update(['sequence' => 0, 'hash' => null]);

    $result = $this->checker->checkAnchors();

    expect(anchorViolations($result))->toBe(['anchor_mismatch'])
        ->and($result->errors()->first()->message)->toContain('statements/');
});

it('detects a cut-off chain end with consistent heads', function (): void {
    DB::table('integrity_versions')->where('sequence', '>', 3)->delete();
    DB::table('integrity_heads')->where('chain', 'global')->update(['sequence' => 3, 'hash' => Version::query()->where('sequence', 3)->value('hash')]);
    DB::table('integrity_anchor_proofs')->where('anchor_id', $this->second)->delete();
    DB::table('integrity_anchors')->where('id', $this->second)->delete();
    DB::table('integrity_heads')->where('chain', 'anchors')->update(['sequence' => 3, 'hash' => DB::table('integrity_anchors')->where('id', $this->first)->value('digest')]);

    expect(anchorViolations($this->checker->checkAnchors()))->toContain('truncated_chain');
});

it('detects a changed anchor row', function (string $column, string $value): void {
    DB::table('integrity_anchors')->where('id', $this->second)->update([$column => $value]);

    expect(anchorViolations($this->checker->checkAnchors()))->toContain('anchor_mismatch');
})->with([
    'digest' => ['digest', str_repeat('e', 64)],
    'merkle root' => ['merkle_root', str_repeat('e', 64)],
    'previous digest' => ['prev_digest', str_repeat('e', 64)],
]);

it('detects anchors that do not continue each other', function (): void {
    DB::table('integrity_anchors')->where('id', $this->second)->update(['from_sequence' => 5]);

    expect($this->checker->checkAnchors()->errors()->pluck('message')->implode("\n"))->toContain('does not continue');
});

it('detects an anchors head that does not match the last anchor', function (): void {
    DB::table('integrity_heads')->where('chain', 'anchors')->update(['sequence' => 3]);

    expect($this->checker->checkAnchors()->errors()->pluck('message')->implode("\n"))->toContain('anchors head');
});

it('detects an anchor without proofs', function (): void {
    DB::table('integrity_anchor_proofs')->where('anchor_id', $this->first)->delete();

    expect($this->checker->checkAnchors()->errors()->pluck('message')->implode("\n"))->toContain('no proof');
});

it('detects a proof that is not valid base64', function (): void {
    DB::table('integrity_anchor_proofs')->where('anchor_id', $this->first)->update(['proof' => '***']);

    expect(anchorViolations($this->checker->checkAnchors()))->toBe(['anchor_mismatch']);
});

it('reports proofs of unknown drivers as unverifiable', function (): void {
    DB::table('integrity_anchor_proofs')->where('anchor_id', $this->first)->update(['driver' => 'gone']);

    expect(anchorViolations($this->checker->checkAnchors()))->toBe(['unverifiable']);
});

it('checks only the latest proof of each driver', function (): void {
    // e.g. an upgraded OpenTimestamps proof replaces the pending one
    DB::table('integrity_anchor_proofs')->insert(['anchor_id' => $this->first, 'driver' => 'disk', 'proof' => base64_encode('statements/wrong.json'), 'created_at' => '2026-10-01 00:00:00.000000']);
    expect($this->checker->checkAnchors()->fails())->toBeTrue();

    $valid = DB::table('integrity_anchor_proofs')->where('anchor_id', $this->first)->orderBy('id')->value('proof');
    DB::table('integrity_anchor_proofs')->insert(['anchor_id' => $this->first, 'driver' => 'disk', 'proof' => $valid, 'created_at' => '2026-10-01 00:00:01.000000']);
    expect($this->checker->checkAnchors()->passes())->toBeTrue();
});

it('accepts pending proofs', function (): void {
    Invoice::query()->create(['number' => 'RE-5', 'total' => '6.00']);
    $this->anchorer->anchor(['pending']);

    expect($this->checker->checkAnchors()->passes())->toBeTrue();
});

it('accepts a statement on the anchor disk that is missing in the database but matches the versions', function (): void {
    // e.g. the transaction failed after the disk anchor was written
    Invoice::query()->create(['number' => 'RE-5', 'total' => '6.00']);
    $hashes = Version::query()->whereBetween('sequence', [6, 6])->pluck('hash');
    $orphan = new AnchorStatement(1, 6, 6, (new MerkleTree)->root($hashes), DB::table('integrity_heads')->where('chain', 'anchors')->value('hash'));
    app(AnchorManager::class)->driver('disk')->submit($orphan);

    expect($this->checker->checkAnchors()->passes())->toBeTrue();
});

it('reports an unreadable statement on the anchor disk', function (): void {
    Storage::disk('anchors')->put('statements/00000000000000000099-'.str_repeat('f', 64).'.json', 'garbage');

    expect(anchorViolations($this->checker->checkAnchors()))->toBe(['anchor_mismatch']);
});

it('dispatches an event on violations', function (): void {
    Event::fake([IntegrityViolationDetected::class]);
    DB::table('integrity_anchors')->where('id', $this->first)->update(['digest' => str_repeat('e', 64)]);

    $this->checker->checkAnchors();

    Event::assertDispatched(IntegrityViolationDetected::class);
});

it('includes the anchors in checkAll', function (): void {
    rewriteChain(forgeFirstTotal(...));

    expect(anchorViolations($this->checker->checkAll()))->toContain('anchor_mismatch');
});
