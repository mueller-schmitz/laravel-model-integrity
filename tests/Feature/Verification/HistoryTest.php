<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use MuellerSchmitz\ModelIntegrity\Events\IntegrityViolationDetected;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;

beforeEach(function (): void {
    $this->checker = app(IntegrityChecker::class);

    Carbon::setTestNow('2026-03-01 08:00:00');
    $this->invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '100.00']);
    Carbon::setTestNow('2026-03-15 12:00:00');
    $this->invoice->update(['total' => '120.00']);
    Carbon::setTestNow('2026-04-01 09:30:00');
    $this->invoice->update(['total' => '130.00']);
    Carbon::setTestNow();
});

it('returns the history oldest first', function (): void {
    $history = $this->checker->getHistory($this->invoice);

    expect($history->pluck('version')->all())->toBe([1, 2, 3])
        ->and($history->every(fn ($version) => $version->isValid() === null))->toBeTrue();
});

it('marks every version as valid when the history is intact', function (): void {
    $history = $this->checker->getHistory($this->invoice, verify: true);

    expect($history->map->isValid()->all())->toBe([true, true, true]);
});

it('marks versions from the first violation on as invalid', function (): void {
    DB::table('integrity_versions')->where('sequence', 2)->update(['snapshot' => '{"total":"1.00"}']);

    $history = $this->checker->getHistory($this->invoice, verify: true);

    expect($history->map->isValid()->all())->toBe([true, false, false]);
});

it('dispatches the violation event when verifying the history', function (): void {
    Event::fake([IntegrityViolationDetected::class]);
    DB::table('integrity_versions')->where('sequence', 2)->update(['snapshot' => '{"total":"1.00"}']);

    $this->checker->getHistory($this->invoice, verify: true);

    Event::assertDispatched(IntegrityViolationDetected::class, fn (IntegrityViolationDetected $event): bool => $event->model?->is($this->invoice) === true);
});

it('finds the version valid at a point in time', function (string $date, ?int $expected): void {
    expect($this->checker->versionAt($this->invoice, $date)?->version)->toBe($expected);
})->with([
    'before the first version' => ['2026-02-28 23:59:59', null],
    'at the first version' => ['2026-03-01 08:00:00', 1],
    'between versions' => ['2026-03-20', 2],
    'after the last version' => ['2027-01-01', 3],
]);

it('accepts date objects in any timezone', function (): void {
    // 12:30 in Berlin (CET, before the switch to summer time) is 11:30 UTC,
    // before the second version at 12:00 UTC.
    $date = new CarbonImmutable('2026-03-15 12:30:00', 'Europe/Berlin');

    expect($this->checker->versionAt($this->invoice, $date)?->version)->toBe(1);
});

it('reads date strings in the app timezone', function (): void {
    config(['app.timezone' => 'Europe/Berlin']);
    date_default_timezone_set('Europe/Berlin');

    try {
        // 13:30 Berlin is 12:30 UTC in March (CET), after the second version.
        expect($this->checker->versionAt($this->invoice, '2026-03-15 13:30:00')?->version)->toBe(2)
            ->and($this->checker->versionAt($this->invoice, '2026-03-15 12:30:00')?->version)->toBe(1);
    } finally {
        date_default_timezone_set('UTC');
    }
});
