<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use MuellerSchmitz\ModelIntegrity\Events\VersionRecorded;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\ModelIntegrity;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\VersionRecorder;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Document;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\UlidRecord;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-28 10:00:00.123456');
    $this->recorder = app(VersionRecorder::class);
    $this->document = Document::withoutEvents(fn () => Document::query()->create(['title' => 'A']));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function assertHashMatchesStoredRow(Version $version): void
{
    $stored = Version::query()->where('sequence', $version->sequence)->firstOrFail();

    expect(app(Hasher::class)->hash($stored->toEnvelope()))->toBe($stored->hash)
        ->and($version->hash)->toBe($stored->hash);
}

it('records the first version of the global chain', function (): void {
    $version = $this->recorder->record($this->document, 'created', ['title' => 'A']);

    // Pest reserves ->sequence() on expectations, so the sequence is asserted directly.
    expect($version->sequence)->toBe(1);

    expect($version)
        ->version->toBe(1)
        ->event->toBe('created')
        ->versionable_type->toBe($this->document->getMorphClass())
        ->versionable_id->toBe((string) $this->document->getKey())
        ->hash_format->toBe(1)
        ->schema_version->toBe(1)
        ->snapshot->toBe(['title' => 'A'])
        ->prev_hash->toBeNull()
        ->global_prev_hash->toBeNull()
        ->hash->toMatch('/^[0-9a-f]{64}$/');

    expect($version->created_at->format('Y-m-d\TH:i:s.u\Z'))->toBe('2026-09-28T10:00:00.123456Z');

    assertHashMatchesStoredRow($version);
});

it('chains versions per model and globally', function (): void {
    $other = Document::withoutEvents(fn () => Document::query()->create(['title' => 'B']));

    $first = $this->recorder->record($this->document, 'created', ['title' => 'A']);
    $second = $this->recorder->record($this->document, 'updated', ['title' => 'A2']);
    $third = $this->recorder->record($other, 'created', ['title' => 'B']);

    expect([$second->sequence, $third->sequence])->toBe([2, 3]);

    expect($second)
        ->version->toBe(2)
        ->prev_hash->toBe($first->hash)
        ->global_prev_hash->toBe($first->hash);

    expect($third)
        ->version->toBe(1)
        ->prev_hash->toBeNull()
        ->global_prev_hash->toBe($second->hash);

    collect([$first, $second, $third])->each(fn (Version $version) => assertHashMatchesStoredRow($version));
});

it('advances the global head', function (): void {
    $this->recorder->record($this->document, 'created', ['title' => 'A']);
    $last = $this->recorder->record($this->document, 'updated', ['title' => 'A2']);

    $head = DB::table('integrity_heads')->where('chain', 'global')->first();

    expect((int) $head->sequence)->toBe(2)
        ->and($head->hash)->toBe($last->hash);
});

it('stores actor, reason, context and schema version', function (): void {
    $user = User::query()->create(['name' => 'Anna']);

    $version = ModelIntegrity::actingAs($user, fn () => $this->recorder->record(
        $this->document,
        'updated',
        ['title' => 'A'],
        schemaVersion: 3,
        reason: 'Typo',
        context: ['ticket' => 'MI-1', 'source' => 'import'],
    ));

    expect($version)
        ->actor_type->toBe($user->getMorphClass())
        ->actor_id->toBe((string) $user->getKey())
        ->reason->toBe('Typo')
        ->context->toBe(['source' => 'import', 'ticket' => 'MI-1'])
        ->schema_version->toBe(3);

    assertHashMatchesStoredRow($version);
});

it('builds a lazy snapshot once while holding the head lock', function (): void {
    $calls = 0;

    $version = $this->recorder->record($this->document, 'created', function () use (&$calls): array {
        $calls++;

        return ['title' => 'A'];
    });

    expect($calls)->toBe(1)
        ->and($version->snapshot)->toBe(['title' => 'A']);

    assertHashMatchesStoredRow($version);
});

it('stores string keys', function (): void {
    $record = UlidRecord::withoutEvents(fn () => UlidRecord::query()->create(['title' => 'U']));

    $version = $this->recorder->record($record, 'created', ['title' => 'U']);

    expect($version->versionable_id)->toBe($record->getKey());
    assertHashMatchesStoredRow($version);
});

it('dispatches VersionRecorded after commit', function (): void {
    Event::fake([VersionRecorded::class]);

    DB::transaction(function (): void {
        $this->recorder->record($this->document, 'created', ['title' => 'A']);

        Event::assertNotDispatched(VersionRecorded::class);
    });

    Event::assertDispatched(VersionRecorded::class, fn (VersionRecorded $event): bool => $event->version->sequence === 1
        && $event->model->is($this->document));
});

it('does not dispatch VersionRecorded on rollback', function (): void {
    Event::fake([VersionRecorded::class]);

    try {
        DB::transaction(function (): void {
            $this->recorder->record($this->document, 'created', ['title' => 'A']);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(VersionRecorded::class);
    expect(Version::query()->count())->toBe(0);
});

it('rejects models on another connection than the integrity tables', function (): void {
    config(['database.connections.other' => config('database.connections.'.config('database.default'))]);
    config(['model-integrity.connection' => 'other']);

    $this->recorder->record($this->document, 'created', ['title' => 'A']);
})->throws(IntegrityConfigurationException::class, 'connection');

it('fails without a global head', function (): void {
    DB::table('integrity_heads')->delete();

    $this->recorder->record($this->document, 'created', ['title' => 'A']);
})->throws(IntegrityConfigurationException::class, 'head');
