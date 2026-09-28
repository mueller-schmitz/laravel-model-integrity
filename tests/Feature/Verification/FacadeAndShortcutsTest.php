<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker as Checker;

beforeEach(function (): void {
    Carbon::setTestNow('2026-03-01 08:00:00');
    $this->invoice = Invoice::query()->create(['number' => 'RE-1', 'total' => '100.00']);
    Carbon::setTestNow('2026-03-15 12:00:00');
    $this->invoice->update(['total' => '120.00']);
    Carbon::setTestNow();
});

it('resolves the checker as a singleton', function (): void {
    expect(app(Checker::class))->toBe(app(Checker::class))
        ->and(IntegrityChecker::getFacadeRoot())->toBe(app(Checker::class));
});

it('exposes the checker through the facade', function (): void {
    expect(IntegrityChecker::checkModel($this->invoice)->passes())->toBeTrue()
        ->and(IntegrityChecker::getHistory($this->invoice))->toHaveCount(2)
        ->and(IntegrityChecker::versionAt($this->invoice, '2026-03-10')?->version)->toBe(1)
        ->and(IntegrityChecker::checkType(Invoice::class)->passes())->toBeTrue()
        ->and(IntegrityChecker::checkChain()->passes())->toBeTrue()
        ->and(IntegrityChecker::checkAll()->passes())->toBeTrue();
});

it('offers shortcuts on the model', function (): void {
    expect($this->invoice->history()->pluck('version')->all())->toBe([1, 2])
        ->and($this->invoice->history(verify: true)->map->isValid()->all())->toBe([true, true])
        ->and($this->invoice->verifyIntegrity()->passes())->toBeTrue()
        ->and($this->invoice->versionAt('2026-03-20')?->version)->toBe(2);
});
