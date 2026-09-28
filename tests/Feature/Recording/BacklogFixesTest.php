<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use MuellerSchmitz\ModelIntegrity\Exceptions\ImmutableModelException;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\VersionRecorder;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Document;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;

describe('immutable models', function (): void {
    it('allows touching, which only changes excluded attributes', function (): void {
        Carbon::setTestNow('2026-09-28 10:00:00');
        $document = Document::query()->create(['title' => 'A']);

        Carbon::setTestNow('2026-09-28 11:00:00');
        $document->touch();
        Carbon::setTestNow();

        expect($document->fresh()->updated_at->format('H:i'))->toBe('11:00')
            ->and($document->integrityVersions()->count())->toBe(1);
    });

    it('still refuses changes of recorded attributes', function (): void {
        $document = Document::query()->create(['title' => 'A']);

        $document->update(['title' => 'B']);
    })->throws(ImmutableModelException::class);
});

describe('versions', function (): void {
    it('cannot be created from application code', function (): void {
        Version::query()->create([
            'sequence' => 1, 'versionable_type' => 'X', 'versionable_id' => '1', 'version' => 1, 'event' => 'created',
            'hash_format' => 1, 'schema_version' => 1, 'snapshot' => '{}', 'hash' => str_repeat('a', 64),
            'created_at' => '2026-09-28 10:00:00.000000',
        ]);
    })->throws(ImmutableModelException::class);
});

describe('configuration errors', function (): void {
    it('explains a model without a key', function (): void {
        app(VersionRecorder::class)->record(new Document, 'created', ['title' => 'A']);
    })->throws(IntegrityConfigurationException::class, 'no key');

    it('explains a declared but uninitialized typed property', function (): void {
        $document = new class extends Document
        {
            protected $table = 'documents';

            protected string $integrityMode;
        };

        $document->getIntegrityMode();
    })->throws(IntegrityConfigurationException::class, 'integrityMode');
});

describe('snapshot event names', function (): void {
    it('rejects invalid names', function (string $event): void {
        Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00'])->recordIntegritySnapshot($event);
    })->with([
        'empty' => [''],
        'too long' => [str_repeat('a', 33)],
        'uppercase' => ['Snapshot'],
        'space' => ['schema migrated'],
    ])->throws(InvalidArgumentException::class);

    it('accepts snake case names up to 32 characters', function (): void {
        $version = Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00'])
            ->recordIntegritySnapshot(str_repeat('a', 30).'_2');

        expect($version->event)->toHaveLength(32);
    });
});
