<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Facades\IntegritySubjects;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Customer;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Order;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;

beforeEach(function (): void {
    $this->customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
    $this->order = Order::query()->create(['customer_id' => $this->customer->id, 'number' => 'O-1', 'shipping_name' => 'Ada Lovelace', 'shipping_address' => ['city' => 'London'], 'total' => '5.00']);
});

function driftMessages(): array
{
    return app(IntegrityChecker::class)->checkAll()->errors()->map(fn ($error): string => (string) $error)->values()->all();
}

it('shreds a data subject and records it in the chain with the reason', function (): void {
    IntegritySubjects::shred($this->customer, 'Art. 17 GDPR request #42');

    $shredding = Version::query()->where('versionable_type', 'model-integrity.subject')->orderBy('sequence')->get()->last();

    expect(IntegritySubjects::isShredded($this->customer))->toBeTrue()
        ->and($shredding->event)->toBe('updated')
        ->and($shredding->reason)->toBe('Art. 17 GDPR request #42')
        ->and($shredding->snapshot['shredded_at'])->not->toBeNull();
});

it('makes the personal data of all models of the subject unreadable, but keeps the chain valid', function (): void {
    IntegritySubjects::shred($this->customer);

    $order = $this->order->integrityVersions()->sole()->revealedSnapshot();
    $customer = $this->customer->integrityVersions()->sole()->revealedSnapshot();

    expect($order->shredded)->toBe(['shipping_address', 'shipping_name'])
        ->and($order->snapshot['total'])->toBe('5.00')
        ->and($customer->snapshot['name'])->toBeNull()
        ->and(app(IntegrityChecker::class)->checkChain()->passes())->toBeTrue()
        ->and(app(IntegrityChecker::class)->checkAnchors()->passes())->toBeTrue();
});

it('reports personal data left in the model rows after shredding', function (): void {
    IntegritySubjects::shred($this->customer);

    expect(implode("\n", driftMessages()))->toContain('[email, name] still hold data')
        ->toContain('[shipping_address, shipping_name] still hold data');
});

it('passes once the application anonymized the rows', function (): void {
    IntegritySubjects::shred($this->customer);

    $this->customer->refresh()->update(['name' => 'deleted', 'email' => null]);
    $this->order->refresh()->update(['shipping_name' => '', 'shipping_address' => null]);

    expect(driftMessages())->toBe([]);
});

it('also accepts anonymized rows that were changed without recording', function (): void {
    // e.g. anonymized by an SQL script in a maintenance window
    IntegritySubjects::shred($this->customer);
    DB::table('customers')->where('id', $this->customer->id)->update(['name' => 'deleted', 'email' => null]);
    DB::table('orders')->where('id', $this->order->id)->update(['shipping_name' => '', 'shipping_address' => null]);

    expect(driftMessages())->toBe([]);
});

it('still detects changes of other attributes after shredding', function (): void {
    IntegritySubjects::shred($this->customer);
    DB::table('orders')->where('id', $this->order->id)->update(['shipping_name' => '', 'shipping_address' => null, 'total' => '1.00']);

    expect(implode("\n", driftMessages()))->toContain('in [total]');
});

it('detects a swapped or damaged key', function (): void {
    $other = Customer::query()->create(['number' => 'C-2', 'name' => 'Bob', 'email' => null]);
    $otherKey = DB::table('integrity_subject_keys')->where('subject', $other->getMorphClass().':'.$other->getKey())->value('key');
    DB::table('integrity_subject_keys')->where('subject', $this->customer->getMorphClass().':'.$this->customer->getKey())->update(['key' => $otherKey]);
    app()->forgetScopedInstances();

    expect(implode("\n", driftMessages()))->toContain('cannot be decrypted');
});

it('shreds a subject that has no key yet, so it never gets one', function (): void {
    IntegritySubjects::shred('App\\Models\\Customer:999');

    expect(IntegritySubjects::isShredded('App\\Models\\Customer:999'))->toBeTrue();
});

it('shreds from the command line', function (): void {
    $this->artisan('model-integrity:shred', ['subject' => Customer::class, 'id' => $this->customer->id, '--reason' => 'request #42'])
        ->expectsOutputToContain('Shredded')
        ->assertExitCode(0);

    $this->artisan('model-integrity:shred', ['subject' => Customer::class, 'id' => $this->customer->id])
        ->expectsOutputToContain('already shredded')
        ->assertExitCode(0);

    expect(IntegritySubjects::isShredded($this->customer))->toBeTrue();
});
