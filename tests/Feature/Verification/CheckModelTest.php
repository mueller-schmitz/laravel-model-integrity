<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Contract;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Post;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Tag;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

/*
 * Every test manipulates the stored data with plain SQL, the way an attacker
 * with database access would, and asserts which violation is reported.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-28 10:00:00');

    // v1..v3 of one invoice, sequences 1..3
    $this->invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '100.00']);
    $this->invoice->update(['total' => '120.00']);
    $this->invoice->update(['note' => 'late']);

    $this->checker = app(IntegrityChecker::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return list<string> e.g. ['hash_mismatch@2', 'truncated_chain@-']
 */
function violations(IntegrityResult $result): array
{
    return $result->errors()
        ->map(fn (IntegrityError $error): string => $error->type->value.'@'.($error->version ?? '-'))
        ->sort()
        ->values()
        ->all();
}

function tamperVersion(int $sequence, array $changes): void
{
    DB::table('integrity_versions')->where('sequence', $sequence)->update($changes);
}

function rehash(int $sequence): void
{
    $version = Version::query()->where('sequence', $sequence)->sole();
    tamperVersion($sequence, ['hash' => app(Hasher::class)->hash($version->toEnvelope())]);
}

function setHead(int $sequence): void
{
    DB::table('integrity_heads')->where('chain', 'global')->update([
        'sequence' => $sequence,
        'hash' => DB::table('integrity_versions')->where('sequence', $sequence)->value('hash'),
    ]);
}

describe('intact history', function (): void {
    it('passes', function (): void {
        $result = $this->checker->checkModel($this->invoice);

        expect($result->passes())->toBeTrue()
            ->and($result->checkedVersions())->toBe(3)
            ->and($result->lastValidVersion())->toBe(3);
    });

    it('passes after a recorded hard delete', function (): void {
        $this->invoice->delete();

        expect($this->checker->checkModel($this->invoice)->passes())->toBeTrue();
    });

    it('passes for soft deletes, restores and force deletes', function (): void {
        $contract = Contract::query()->create(['title' => 'Lease']);
        $contract->delete();
        expect($this->checker->checkModel($contract)->passes())->toBeTrue();

        $contract->restore();
        expect($this->checker->checkModel($contract)->passes())->toBeTrue();

        $contract->forceDelete();
        expect($this->checker->checkModel($contract)->passes())->toBeTrue();
    });

    it('ignores changes of excluded attributes', function (): void {
        DB::table('invoices')->update(['updated_at' => '2030-01-01 00:00:00']);

        expect($this->checker->checkModel($this->invoice)->passes())->toBeTrue();
    });
});

describe('tampered versions', function (): void {
    it('detects a changed snapshot', function (): void {
        tamperVersion(2, ['snapshot' => '{"total":"1.00"}']);

        $result = $this->checker->checkModel($this->invoice);

        expect(violations($result))->toBe(['hash_mismatch@2'])
            ->and($result->lastValidVersion())->toBe(1);
    });

    it('detects a forged version with a recomputed hash', function (): void {
        tamperVersion(2, ['snapshot' => '{"total":"1.00"}']);
        rehash(2);

        $result = $this->checker->checkModel($this->invoice);

        expect(violations($result))->toBe(['broken_chain@2', 'broken_chain@2'])
            ->and($result->lastValidVersion())->toBe(1);
    });

    it('detects a changed prev_hash', function (): void {
        tamperVersion(3, ['prev_hash' => str_repeat('0', 64)]);

        expect(violations($this->checker->checkModel($this->invoice)))->toContain('hash_mismatch@3');
    });

    it('detects a changed global_prev_hash', function (): void {
        tamperVersion(2, ['global_prev_hash' => str_repeat('0', 64)]);

        $result = $this->checker->checkModel($this->invoice);

        expect(violations($result))->toBe(['broken_chain@-', 'hash_mismatch@2'])
            ->and($result->lastValidVersion())->toBe(1);
    });

    it('detects an unknown hash format', function (): void {
        tamperVersion(1, ['hash_format' => 99]);

        $result = $this->checker->checkModel($this->invoice);

        expect(violations($result))->toBe(['hash_mismatch@1'])
            ->and($result->lastValidVersion())->toBeNull();
    });
});

describe('removed versions', function (): void {
    it('detects a removed version in the middle', function (): void {
        DB::table('integrity_versions')->where('sequence', 2)->delete();

        $result = $this->checker->checkModel($this->invoice);

        expect(violations($result))->toBe(['sequence_gap@-', 'version_gap@3'])
            ->and($result->lastValidVersion())->toBe(1);
    });

    it('detects a removed last version', function (): void {
        DB::table('integrity_versions')->where('sequence', 3)->delete();

        $result = $this->checker->checkModel($this->invoice);

        expect(violations($result))->toBe(['state_drift@-', 'truncated_chain@-'])
            ->and($result->lastValidVersion())->toBe(2);
    });

    it('detects a head that was reset behind the last version', function (): void {
        setHead(2);

        expect(violations($this->checker->checkModel($this->invoice)))->toBe(['truncated_chain@-']);
    });

    it('only sees the state drift when the last version and the head were removed together', function (): void {
        // Undetectable by the chain alone; this is what external anchors are for.
        DB::table('integrity_versions')->where('sequence', 3)->delete();
        setHead(2);

        expect(violations($this->checker->checkModel($this->invoice)))->toBe(['state_drift@-']);
    });
});

describe('state drift', function (): void {
    it('detects mass updates', function (): void {
        Invoice::query()->update(['total' => '1.00']);

        $result = $this->checker->checkModel($this->invoice);

        expect(violations($result))->toBe(['state_drift@-'])
            ->and($result->errors()->sole()->message)->toContain('total')
            ->and($result->lastValidVersion())->toBe(3);
    });

    it('detects quiet saves', function (): void {
        $this->invoice->total = '5.00';
        $this->invoice->saveQuietly();

        expect(violations($this->checker->checkModel($this->invoice)))->toBe(['state_drift@-']);
    });

    it('detects a row deleted without a recorded delete', function (): void {
        DB::table('invoices')->delete();

        expect(violations($this->checker->checkModel($this->invoice)))->toBe(['state_drift@-']);
    });

    it('detects a row restored after a recorded hard delete', function (): void {
        $row = (array) DB::table('invoices')->first();
        $this->invoice->delete();
        DB::table('invoices')->insert($row);

        expect(violations($this->checker->checkModel($this->invoice)))->toBe(['state_drift@-']);
    });

    it('detects a model without any recorded version', function (): void {
        $unrecorded = Invoice::withoutEvents(fn () => Invoice::query()->create(['number' => 'RE-2', 'total' => '1.00']));

        $result = $this->checker->checkModel($unrecorded);

        expect(violations($result))->toBe(['state_drift@-'])
            ->and($result->checkedVersions())->toBe(0)
            ->and($result->lastValidVersion())->toBeNull();
    });

    it('detects unrecorded relation changes', function (): void {
        $post = Post::query()->create(['title' => 'Hello']);
        $post->tags()->attach(Tag::query()->create(['name' => 'a']));

        expect(violations($this->checker->checkModel($post)))->toBe(['state_drift@-']);
    });
});

it('checks versions of other models in between', function (): void {
    $other = Invoice::query()->create(['number' => 'RE-2', 'total' => '1.00']); // sequence 4
    $this->invoice->update(['total' => '130.00']);                           // sequence 5

    DB::table('integrity_versions')->where('sequence', 4)->delete();

    $result = $this->checker->checkModel($this->invoice);

    // The gap belongs to another model and does not invalidate this history.
    expect(violations($result))->toBe(['sequence_gap@-'])
        ->and($result->lastValidVersion())->toBe(4)
        ->and(violations($this->checker->checkModel($other)))->toContain('state_drift@-');
});
