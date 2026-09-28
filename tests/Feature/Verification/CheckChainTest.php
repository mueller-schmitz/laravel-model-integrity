<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Document;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

beforeEach(function (): void {
    $this->checker = app(IntegrityChecker::class);
});

function seedChain(): void
{
    $invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']); // 1
    Document::query()->create(['title' => 'A']);                                    // 2
    $invoice->update(['total' => '2.00']);                                          // 3
    Document::query()->create(['title' => 'B']);                                    // 4
}

/**
 * @return list<string> e.g. ['sequence_gap:2']
 */
function chainViolations(IntegrityResult $result): array
{
    return $result->errors()
        ->map(fn (IntegrityError $error): string => $error->type->value.':'.($error->sequence ?? '-'))
        ->values()
        ->all();
}

it('passes an intact chain of several models', function (): void {
    seedChain();

    $result = $this->checker->checkChain();

    expect($result->passes())->toBeTrue()
        ->and($result->checkedVersions())->toBe(4);
});

it('passes an empty chain', function (): void {
    expect($this->checker->checkChain()->passes())->toBeTrue();
});

it('detects a chain emptied behind the head', function (): void {
    seedChain();
    DB::table('integrity_versions')->delete();

    expect(chainViolations($this->checker->checkChain()))->toBe(['truncated_chain:1']);
});

it('detects removed versions', function (int $sequence): void {
    seedChain();
    DB::table('integrity_versions')->where('sequence', $sequence)->delete();

    expect(chainViolations($this->checker->checkChain()))->toBe(["sequence_gap:{$sequence}"]);
})->with([1, 2, 3]);

it('detects a removed end of the chain', function (): void {
    seedChain();
    DB::table('integrity_versions')->where('sequence', '>=', 3)->delete();

    expect(chainViolations($this->checker->checkChain()))->toBe(['truncated_chain:3']);
});

it('detects a head reset behind the last version', function (): void {
    seedChain();
    DB::table('integrity_heads')->update(['sequence' => 3, 'hash' => DB::table('integrity_versions')->where('sequence', 3)->value('hash')]);

    expect(chainViolations($this->checker->checkChain()))->toBe(['truncated_chain:4']);
});

it('detects a tampered version and names its model', function (): void {
    seedChain();
    DB::table('integrity_versions')->where('sequence', 2)->update(['snapshot' => '{"title":"X"}']);

    $error = $this->checker->checkChain()->errors()->sole();

    expect($error->type->value)->toBe('hash_mismatch')
        ->and($error->versionableType)->toBe((new Document)->getMorphClass())
        ->and($error->version)->toBe(1)
        ->and($error->sequence)->toBe(2);
});

it('detects a forged version with a recomputed hash', function (): void {
    seedChain();
    DB::table('integrity_versions')->where('sequence', 2)->update(['snapshot' => '{"title":"X"}']);
    $forged = Version::query()->where('sequence', 2)->sole();
    DB::table('integrity_versions')->where('sequence', 2)->update(['hash' => app(Hasher::class)->hash($forged->toEnvelope())]);

    $error = $this->checker->checkChain()->errors()->sole();

    expect($error->type->value)->toBe('broken_chain')
        ->and($error->sequence)->toBe(3)
        ->and($error->versionableType)->toBe((new Document)->getMorphClass())
        ->and($error->version)->toBe(1);
});
