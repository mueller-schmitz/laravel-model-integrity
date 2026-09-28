<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Recording\SnapshotBuilder;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\CastSample;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Contract;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\InvoicePriority;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\InvoiceStatus;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Post;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Tag;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\UlidRecord;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Support\TagCollection;

/*
 * Expected values are literals on purpose: the CI database matrix proves that
 * MySQL, MariaDB, PostgreSQL and SQLite produce the identical snapshot.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-28 10:00:00');
    $this->builder = app(SnapshotBuilder::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function createInvoiceQuietly(array $attributes = []): Invoice
{
    return Invoice::withoutEvents(fn () => Invoice::query()->create(array_merge([
        'number' => 'RE-1',
        'total' => '100.5',
        'rate' => 0.1,
        'paid' => true,
        'quantity' => 3,
        'issued_at' => '2026-09-28 12:30:45',
        'due_on' => '2026-10-15',
        'meta' => ['b' => 1, 'a' => ['y' => true, 'x' => null]],
        'status' => InvoiceStatus::Sent,
        'priority' => InvoicePriority::High,
        'secret' => 'top secret',
        'note' => 'n/a',
    ], $attributes)));
}

it('normalizes every attribute by its cast', function (): void {
    $invoice = createInvoiceQuietly();
    $ciphertext = DB::table('invoices')->value('secret');

    expect($this->builder->build($invoice))->toBe([
        'created_at' => '2026-09-28T10:00:00.000000Z',
        'due_on' => '2026-10-15',
        'id' => $invoice->getKey(),
        'issued_at' => '2026-09-28T12:30:45.000000Z',
        'meta' => ['a' => ['x' => null, 'y' => true], 'b' => 1],
        'note' => 'n/a',
        'number' => 'RE-1',
        'paid' => true,
        'priority' => 2,
        'quantity' => 3,
        'rate' => '0.1',
        'secret' => $ciphertext,
        'status' => 'sent',
        'total' => '100.50',
        'updated_at' => '2026-09-28T10:00:00.000000Z',
    ]);
});

it('normalizes the remaining cast types', function (): void {
    Carbon::setTestNow(); // older Carbon versions parse with the timezone of the test clock
    config(['app.timezone' => 'Europe/Berlin']);
    date_default_timezone_set('Europe/Berlin');

    try {
        $sample = CastSample::withoutEvents(fn () => CastSample::query()->create([
            'happened_at' => '2026-09-28 12:30',
            'stamp' => 1700000000,
            'frozen_at' => '2026-09-28 12:30:45',
            'born_on' => '1990-05-17',
            'payload' => (object) ['b' => 1, 'a' => [2, 1]],
            'items' => [3, 1],
            'tags' => new TagCollection(['y' => 1, 'x' => 2]),
            'statuses' => [InvoiceStatus::Sent, InvoiceStatus::Paid],
            'label' => 'abc',
            'amount' => 12.5,
            'password' => 'secret',
        ]));
        $storedPassword = DB::table('cast_samples')->value('password');

        expect($storedPassword)->not->toBe('secret')
            ->and($this->builder->build($sample))->toBe([
                'amount' => 1250,
                'born_on' => '1990-05-17',
                'frozen_at' => '2026-09-28T10:30:45.000000Z',
                'happened_at' => '2026-09-28T10:30:00.000000Z',
                'id' => $sample->getKey(),
                'items' => [3, 1],
                'label' => 'abc',
                'password' => $storedPassword,
                'payload' => ['a' => [2, 1], 'b' => 1],
                'stamp' => 1700000000,
                'statuses' => ['sent', 'paid'],
                'tags' => ['x' => 2, 'y' => 1],
            ]);
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('keeps the stored ciphertext of encrypted attributes', function (): void {
    $snapshot = $this->builder->build(createInvoiceQuietly());

    expect($snapshot['secret'])->toBeString()->not->toBe('top secret')
        ->and(decrypt($snapshot['secret'], false))->toBe('top secret');
});

it('reads the stored row instead of the in-memory attributes', function (): void {
    $invoice = createInvoiceQuietly();
    $invoice->total = '999.99';
    $invoice->note = 'unsaved';

    expect($this->builder->build($invoice))
        ->total->toBe('100.50')
        ->note->toBe('n/a');
});

it('keeps null values', function (): void {
    $snapshot = $this->builder->build(createInvoiceQuietly([
        'rate' => null, 'issued_at' => null, 'due_on' => null, 'meta' => null, 'secret' => null, 'note' => null,
    ]));

    expect($snapshot)->rate->toBeNull()
        ->issued_at->toBeNull()
        ->due_on->toBeNull()
        ->meta->toBeNull()
        ->secret->toBeNull()
        ->note->toBeNull();
});

it('keeps date casts as plain dates regardless of the app timezone', function (): void {
    Carbon::setTestNow();
    config(['app.timezone' => 'Europe/Berlin']);
    date_default_timezone_set('Europe/Berlin');

    try {
        $snapshot = $this->builder->build(createInvoiceQuietly(['due_on' => '2026-10-15']));

        expect($snapshot['due_on'])->toBe('2026-10-15');
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('reads the row with a lock on request', function (): void {
    DB::enableQueryLog();

    $this->builder->build(createInvoiceQuietly(), lock: true);

    $select = collect(DB::getQueryLog())->pluck('query')->first(fn (string $sql): bool => str_starts_with($sql, 'select'));

    // SQLite has no row locks; Laravel's grammar drops the clause there.
    expect($select)->toBeString()->when(
        DB::connection()->getDriverName() !== 'sqlite',
        fn ($sql) => $sql->toContain('for update'),
    );
});

it('converts dates from the app timezone to utc', function (): void {
    // Some Carbon versions parse with the timezone of the mocked "now" instead
    // of the default timezone, so the mock must not interfere here.
    Carbon::setTestNow();
    config(['app.timezone' => 'Europe/Berlin']);
    date_default_timezone_set('Europe/Berlin');

    try {
        $snapshot = $this->builder->build(createInvoiceQuietly(['issued_at' => '2026-09-28 12:30:45']));

        expect($snapshot['issued_at'])->toBe('2026-09-28T10:30:45.000000Z');
    } finally {
        date_default_timezone_set('UTC');
    }
});

it('removes excluded attributes', function (): void {
    expect($this->builder->build(createInvoiceQuietly(), except: ['updated_at', 'secret']))
        ->not->toHaveKeys(['updated_at', 'secret'])
        ->toHaveKey('total');
});

it('reads rows hidden by global scopes', function (): void {
    $contract = Contract::withoutEvents(function (): Contract {
        $contract = Contract::query()->create(['title' => 'Lease']);
        $contract->delete();

        return $contract;
    });

    expect($this->builder->build($contract))
        ->title->toBe('Lease')
        ->deleted_at->toBe('2026-09-28T10:00:00.000000Z');
});

it('supports string keys', function (): void {
    $record = UlidRecord::withoutEvents(fn () => UlidRecord::query()->create(['title' => 'ULID']));

    expect($this->builder->build($record))->id->toBe($record->getKey());
});

it('adds the sorted keys of declared relations', function (): void {
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hello']));
    $tags = collect(range(1, 11))->map(fn (int $i) => Tag::query()->create(['name' => "tag{$i}"]));
    $post->tags()->sync($tags->pluck('id')->reverse()->all());

    expect($this->builder->build($post, relations: ['tags'])['@relations'])->toBe([
        'tags' => ['1', '10', '11', '2', '3', '4', '5', '6', '7', '8', '9'],
    ]);
});

it('records empty relations as an empty list', function (): void {
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hello']));

    expect($this->builder->build($post, relations: ['tags'])['@relations'])->toBe(['tags' => []]);
});

it('rejects an unknown relation', function (): void {
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hello']));

    $this->builder->build($post, relations: ['comments']);
})->throws(IntegrityConfigurationException::class, 'comments');

it('fails when the row does not exist', function (): void {
    $invoice = createInvoiceQuietly();
    DB::table('invoices')->delete();

    $this->builder->build($invoice);
})->throws(IntegrityConfigurationException::class);
