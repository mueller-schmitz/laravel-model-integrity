<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Anchoring\Anchorer;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorVerification;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;

/**
 * A driver whose proofs are attested at a set time, or pending.
 */
final class TimedAnchor implements Anchor
{
    public static ?CarbonImmutable $attestedAt = null;

    public function submit(AnchorStatement $statement): string
    {
        return 'proof';
    }

    public function verify(AnchorStatement $statement, string $proof): AnchorVerification
    {
        return self::$attestedAt === null
            ? AnchorVerification::pending('waiting')
            : AnchorVerification::confirmed('attested', self::$attestedAt);
    }
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-01 12:00:00');
    TimedAnchor::$attestedAt = null;
    app(AnchorManager::class)->extend('timed', fn () => new TimedAnchor);
    config(['model-integrity.anchors.drivers' => ['timed'], 'model-integrity.anchors.max_delay_hours' => 72]);

    Invoice::query()->create(['number' => 'RE-1', 'total' => '1.00']);
    app(Anchorer::class)->anchor();
});

afterEach(fn () => CarbonImmutable::setTestNow());

function timelinessMessages(): array
{
    return app(IntegrityChecker::class)->checkAnchors()->errors()->pluck('message')->all();
}

it('accepts an attestation within the maximum delay', function (): void {
    TimedAnchor::$attestedAt = CarbonImmutable::parse('2026-10-02 12:00:00');

    expect(timelinessMessages())->toBe([]);
});

it('rejects an attestation later than the maximum delay after the anchor', function (): void {
    // e.g. a fresh proof for a statement forged afterwards
    TimedAnchor::$attestedAt = CarbonImmutable::parse('2026-10-05 12:00:01');

    expect(implode("\n", timelinessMessages()))->toContain('attested at 2026-10-05');
});

it('accepts a pending proof within the maximum delay', function (): void {
    CarbonImmutable::setTestNow('2026-10-03 12:00:00');

    expect(timelinessMessages())->toBe([]);
});

it('rejects a proof still pending after the maximum delay', function (): void {
    CarbonImmutable::setTestNow('2026-10-04 12:00:01');

    expect(implode("\n", timelinessMessages()))->toContain('still pending');
});

it('rejects versions recorded after the anchor that attests them', function (): void {
    // The anchor claims to be older than what it attests: its time was rewritten.
    DB::table('integrity_anchors')->update(['created_at' => '2026-09-01 00:00:00.000000']);
    TimedAnchor::$attestedAt = CarbonImmutable::parse('2026-09-01 01:00:00');

    expect(implode("\n", timelinessMessages()))->toContain('recorded after');
});

it('measures the delay from the attested versions, not from the unhashed anchor row', function (): void {
    // Versions recorded in January, anchored and attested in October: the
    // anchor row's own time would hide that, as an attacker can set it freely.
    DB::table('integrity_versions')->update(['created_at' => '2026-01-01 00:00:00.000000']);
    TimedAnchor::$attestedAt = CarbonImmutable::parse('2026-10-01 12:30:00');

    expect(implode('
', timelinessMessages()))->toContain('after the versions it attests');
});

it('accepts versions recorded before anchoring was enabled, if anchored right then', function (string $attestedAt, bool $passes): void {
    config(['model-integrity.anchors.since' => '2026-10-01 00:00:00']);
    DB::table('integrity_versions')->update(['created_at' => '2026-01-01 00:00:00.000000']);
    TimedAnchor::$attestedAt = CarbonImmutable::parse($attestedAt);

    expect(timelinessMessages() === [])->toBe($passes);
})->with([
    'anchored when enabled' => ['2026-10-01 12:30:00', true],
    'anchored months later' => ['2026-12-01 12:00:00', false],
]);
