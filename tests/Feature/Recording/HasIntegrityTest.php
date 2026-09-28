<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\SnapshotBuilder;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Contract;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Document;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Post;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Tag;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\UlidRecord;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-28 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function newInvoice(array $attributes = []): Invoice
{
    return Invoice::query()->create(array_merge(['number' => 'RE-1', 'total' => '100.00'], $attributes));
}

/**
 * @return list<string>
 */
function recordedEvents(): array
{
    return Version::query()->orderBy('sequence')->pluck('event')->all();
}

function expectValidHashes(): void
{
    Version::query()->get()->each(
        fn (Version $version) => expect(app(Hasher::class)->hash($version->toEnvelope()))->toBe($version->hash)
    );
}

describe('versioned mode', function (): void {
    it('records a version when a model is created', function (): void {
        $invoice = newInvoice();

        $version = $invoice->integrityVersions()->sole();

        expect($version->event)->toBe('created')
            ->and($version->version)->toBe(1)
            ->and($version->snapshot)->toBe(app(SnapshotBuilder::class)->build($invoice, except: ['updated_at']));

        expectValidHashes();
    });

    it('records a version for each relevant update', function (): void {
        $invoice = newInvoice();
        $invoice->update(['total' => '120.00']);
        $invoice->update(['note' => 'Paid late']);

        expect($invoice->integrityVersions()->pluck('event')->all())->toBe(['created', 'updated', 'updated'])
            ->and($invoice->integrityVersions()->get()->last()->snapshot)
            ->total->toBe('120.00')
            ->note->toBe('Paid late');

        expectValidHashes();
    });

    it('skips saves without relevant changes', function (): void {
        $invoice = newInvoice();

        $invoice->save();
        Carbon::setTestNow('2026-09-28 11:00:00');
        $invoice->touch();

        expect(recordedEvents())->toBe(['created']);
    });

    it('rolls back the model change when recording fails', function (): void {
        $invoice = newInvoice();
        DB::table('integrity_heads')->delete();

        expect(fn () => $invoice->update(['total' => '999.00']))->toThrow(IntegrityConfigurationException::class)
            ->and($invoice->fresh()->total)->toBe('100.00');

        expect(fn () => newInvoice(['number' => 'RE-2']))->toThrow(IntegrityConfigurationException::class)
            ->and(Invoice::query()->count())->toBe(1);
    });

    it('supports string keys', function (): void {
        $record = UlidRecord::query()->create(['title' => 'U']);
        $record->update(['title' => 'U2']);

        expect($record->integrityVersions()->pluck('version')->all())->toBe([1, 2]);
    });
});

describe('immutable mode', function (): void {
    it('records the creation', function (): void {
        Document::query()->create(['title' => 'Contract']);

        expect(recordedEvents())->toBe(['created']);
    });

    it('refuses updates and keeps the stored state', function (): void {
        $document = Document::query()->create(['title' => 'Contract']);

        expect(fn () => $document->update(['title' => 'Changed']))->toThrow(ImmutableModelException::class)
            ->and(DB::table('documents')->value('title'))->toBe('Contract')
            ->and(recordedEvents())->toBe(['created']);
    });
});

describe('deletes', function (): void {
    it('refuses deletes in forbid mode', function (): void {
        $document = Document::query()->create(['title' => 'Contract']);

        expect(fn () => $document->delete())->toThrow(ImmutableModelException::class)
            ->and(Document::query()->count())->toBe(1);
    });

    it('records a hard delete with the state before deletion, read with a lock', function (): void {
        $invoice = newInvoice();
        DB::enableQueryLog();
        $invoice->delete();

        $rowRead = collect(DB::getQueryLog())->pluck('query')
            ->first(fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'invoices'));

        expect($rowRead)->when(DB::connection()->getDriverName() !== 'sqlite', fn ($sql) => $sql->toContain('for update'));

        $deleted = Version::query()->where('event', 'deleted')->sole();

        expect(Invoice::query()->count())->toBe(0)
            ->and($deleted->snapshot['number'])->toBe('RE-1')
            ->and($deleted->version)->toBe(2);

        expectValidHashes();
    });

    it('records soft deletes, restores and force deletes', function (): void {
        $contract = Contract::query()->create(['title' => 'Lease']);

        Carbon::setTestNow('2026-09-28 11:00:00');
        $contract->delete();
        $contract->restore();
        $contract->delete();
        $contract->forceDelete();

        $versions = Version::query()->orderBy('sequence')->get();

        expect($versions->pluck('event')->all())->toBe(['created', 'deleted', 'restored', 'deleted', 'force_deleted'])
            ->and($versions[1]->snapshot['deleted_at'])->toBe('2026-09-28T11:00:00.000000Z')
            ->and($versions[2]->snapshot['deleted_at'])->toBeNull()
            ->and($versions[4]->snapshot['title'])->toBe('Lease')
            ->and(Contract::withTrashed()->count())->toBe(0);

        expectValidHashes();
    });
});

describe('reason and context', function (): void {
    it('applies reason and context to the next write only', function (): void {
        $invoice = newInvoice();

        $invoice->withIntegrityReason('Customer complaint')
            ->withIntegrityContext(['ticket' => 'MI-7'])
            ->update(['total' => '90.00']);

        $invoice->update(['total' => '80.00']);

        [, $withReason, $without] = Version::query()->orderBy('sequence')->get()->all();

        expect($withReason->reason)->toBe('Customer complaint')
            ->and($withReason->context)->toBe(['ticket' => 'MI-7'])
            ->and($without->reason)->toBeNull()
            ->and($without->context)->toBeNull();
    });

    it('discards reason and context when nothing was recorded', function (): void {
        $invoice = newInvoice();

        $invoice->withIntegrityReason('Unused')->save();
        $invoice->update(['total' => '80.00']);

        expect(Version::query()->where('event', 'updated')->sole()->reason)->toBeNull();
    });
});

describe('relations', function (): void {
    it('includes declared relations in every snapshot', function (): void {
        $post = Post::query()->create(['title' => 'Hello']);

        expect($post->integrityVersions()->sole()->snapshot['@relations'])->toBe(['tags' => []]);
    });

    it('records relation changes explicitly', function (): void {
        $post = Post::query()->create(['title' => 'Hello']);
        $tags = collect(['a', 'b'])->map(fn (string $name) => Tag::query()->create(['name' => $name]));

        $post->tags()->sync($tags->pluck('id'));
        $version = $post->recordRelation('tags');

        expect($version->event)->toBe('relation_synced')
            ->and($version->snapshot['@relations']['tags'])->toBe($tags->pluck('id')->map(fn ($id) => (string) $id)->all());

        expectValidHashes();
    });

    it('rejects undeclared relations', function (): void {
        Invoice::withoutEvents(fn () => newInvoice())->recordRelation('lines');
    })->throws(IntegrityConfigurationException::class, 'lines');
});

describe('limits', function (): void {
    it('does not see mass updates', function (): void {
        newInvoice();

        Invoice::query()->where('number', 'RE-1')->update(['total' => '1.00']);

        expect(recordedEvents())->toBe(['created']);
    });

    it('records increments', function (): void {
        $invoice = newInvoice();
        $invoice->increment('quantity', 2);

        expect(recordedEvents())->toBe(['created', 'updated'])
            ->and(Version::query()->orderByDesc('sequence')->first()->snapshot['quantity'])->toBe(3);
    });

    it('does not see quiet saves', function (): void {
        $invoice = newInvoice();
        $invoice->total = '2.00';
        $invoice->saveQuietly();

        expect(recordedEvents())->toBe(['created']);
    });
});

it('rejects an invalid mode', function (): void {
    $document = new class extends Document
    {
        protected $table = 'documents';

        protected string $integrityMode = 'append-only';
    };

    $document->fill(['title' => 'X'])->save();
})->throws(IntegrityConfigurationException::class, 'append-only');
