<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use MuellerSchmitz\ModelIntegrity\Events\IntegrityViolationDetected;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Document;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Tag;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityErrorType;

beforeEach(function (): void {
    $this->checker = app(IntegrityChecker::class);

    $this->first = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);   // 1
    $this->second = Invoice::query()->create(['number' => 'RE-2', 'total' => '2.00']);  // 2
    $this->first->update(['total' => '1.50']);                                          // 3
    $this->document = Document::query()->create(['title' => 'A']);                     // 4
});

/**
 * @return list<string> e.g. ['state_drift:2']
 */
function violationsById(iterable $errors): array
{
    return collect($errors)
        ->map(fn (IntegrityError $error): string => $error->type->value.':'.($error->versionableId ?? '-'))
        ->sort()
        ->values()
        ->all();
}

describe('checkType', function (): void {
    it('passes when all models of the type are intact', function (): void {
        $result = $this->checker->checkType(Invoice::class);

        expect($result->passes())->toBeTrue()
            ->and($result->checkedVersions())->toBe(3);
    });

    it('reports violations per model', function (): void {
        DB::table('integrity_versions')->where('sequence', 2)->update(['snapshot' => '{"total":"9.00"}']);
        Invoice::query()->whereKey($this->first->getKey())->update(['total' => '7.00']);

        $id = fn (Invoice $invoice): string => (string) $invoice->getKey();

        // The second invoice also drifts: its row no longer matches the tampered snapshot.
        expect(violationsById($this->checker->checkType(Invoice::class)->errors()))
            ->toBe(['hash_mismatch:'.$id($this->second), 'state_drift:'.$id($this->first), 'state_drift:'.$id($this->second)]);
    });

    it('checks deleted models', function (): void {
        $this->second->delete();
        expect($this->checker->checkType(Invoice::class)->passes())->toBeTrue();

        DB::table('invoices')->where('id', $this->first->getKey())->delete();
        expect(violationsById($this->checker->checkType(Invoice::class)->errors()))
            ->toBe(['state_drift:'.$this->first->getKey()]);
    });

    it('reports rows without any recorded version', function (): void {
        $unrecorded = Invoice::withoutEvents(fn () => Invoice::query()->create(['number' => 'RE-3', 'total' => '3.00']));

        expect(violationsById($this->checker->checkType(Invoice::class)->errors()))
            ->toBe(['state_drift:'.$unrecorded->getKey()]);
    });

    it('rejects models without the trait', function (): void {
        $this->checker->checkType(Tag::class);
    })->throws(IntegrityConfigurationException::class);

    it('dispatches a single event for the whole type', function (): void {
        Event::fake([IntegrityViolationDetected::class]);
        Invoice::query()->update(['total' => '0.00']);

        $this->checker->checkType(Invoice::class);

        Event::assertDispatchedTimes(IntegrityViolationDetected::class, 1);
        Event::assertDispatched(IntegrityViolationDetected::class, fn (IntegrityViolationDetected $event): bool => $event->model === null
            && $event->result->errors()->count() === 2);
    });
});

describe('checkAll', function (): void {
    it('passes when everything is intact', function (): void {
        $result = $this->checker->checkAll();

        expect($result->passes())->toBeTrue()
            ->and($result->checkedVersions())->toBe(4);
    });

    it('combines chain, model and state violations without duplicates', function (): void {
        DB::table('integrity_versions')->where('sequence', 4)->update(['snapshot' => '{"title":"X"}']);
        DB::table('integrity_versions')->where('sequence', 2)->delete();

        $errors = $this->checker->checkAll()->errors();

        // state_drift twice: the second invoice lost its only version, and the
        // document row no longer matches its tampered snapshot.
        expect(violationsById($errors))->toBe([
            'hash_mismatch:'.$this->document->getKey(),
            'sequence_gap:-',
            'state_drift:'.$this->document->getKey(),
            'state_drift:'.$this->second->getKey(),
        ]);
    });

    it('reports versions of unknown model types as unverifiable', function (): void {
        DB::table('integrity_versions')->where('sequence', 4)->update(['versionable_type' => 'App\Models\Removed']);

        $errors = $this->checker->checkAll()->errors();
        $unverifiable = $errors->firstWhere('type', IntegrityErrorType::Unverifiable);

        expect($unverifiable)->not->toBeNull()
            ->and((string) $unverifiable)->toContain('App\Models\Removed')->toContain('does not exist');
    });

    it('checks versions recorded under a former morph class and reports the mismatch', function (): void {
        DB::table('integrity_versions')->where('sequence', 1)->update(['snapshot' => '{"total":"9.00"}']);
        Relation::morphMap(['invoice' => Invoice::class]);

        try {
            $errors = $this->checker->checkAll()->errors();
        } finally {
            Relation::morphMap([], false);
        }

        $unverifiable = $errors->firstWhere('type', IntegrityErrorType::Unverifiable);

        expect($unverifiable)->not->toBeNull()
            ->and((string) $unverifiable)->toContain(Invoice::class)->toContain('invoice')
            ->and(violationsById($errors->where('type', IntegrityErrorType::HashMismatch)))->toBe(['hash_mismatch:'.$this->first->getKey()]);
    });

    it('dispatches a single event', function (): void {
        Event::fake([IntegrityViolationDetected::class]);
        DB::table('integrity_versions')->where('sequence', 4)->update(['snapshot' => '{"title":"X"}']);

        $this->checker->checkAll();

        Event::assertDispatchedTimes(IntegrityViolationDetected::class, 1);
    });
});

it('dispatches an event for a failed model check', function (): void {
    Event::fake([IntegrityViolationDetected::class]);
    Invoice::query()->update(['total' => '0.00']);

    $this->checker->checkModel($this->first);

    Event::assertDispatched(IntegrityViolationDetected::class, fn (IntegrityViolationDetected $event): bool => $event->model?->is($this->first) === true);
});

it('dispatches no event when the check passes', function (): void {
    Event::fake([IntegrityViolationDetected::class]);

    $this->checker->checkModel($this->first);
    $this->checker->checkAll();

    Event::assertNotDispatched(IntegrityViolationDetected::class);
});
