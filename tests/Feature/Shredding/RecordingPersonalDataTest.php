<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ShreddedSubjectException;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectKeys;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Customer;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Order;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;

function storedSnapshot(Version $version): string
{
    return (string) DB::table('integrity_versions')->where('id', $version->id)->value('snapshot');
}

it('records personal attributes only encrypted', function (): void {
    $customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']);

    $version = $customer->integrityVersions()->sole();

    expect(storedSnapshot($version))->not->toContain('Ada')->not->toContain('ada@example.com')->toContain('C-1')
        ->and($version->snapshot['name'])->toHaveKey('@encrypted')
        ->and($customer->verifyIntegrity()->passes())->toBeTrue();
});

it('encrypts with the key of the data subject, also for other models', function (): void {
    $customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada', 'email' => null]);
    $order = Order::query()->create(['customer_id' => $customer->id, 'number' => 'O-1', 'shipping_name' => 'Ada', 'shipping_address' => ['city' => 'London'], 'total' => '5.00']);

    $customerKey = $customer->integrityVersions()->sole()->snapshot['name']['@encrypted']['k'];
    $orderSnapshot = $order->integrityVersions()->sole()->snapshot;

    expect($orderSnapshot['shipping_name']['@encrypted']['k'])->toBe($customerKey)
        ->and($orderSnapshot['shipping_address']['@encrypted']['k'])->toBe($customerKey)
        ->and($orderSnapshot['total'])->toBe('5.00')
        ->and(DB::table('integrity_subject_keys')->count())->toBe(1);
});

it('reveals the recorded personal data while the key exists', function (): void {
    $customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada', 'email' => 'ada@example.com']);
    $customer->update(['email' => 'ada@lovelace.example']);

    $revealed = $customer->integrityVersions()->get()->map(fn (Version $version): array => $version->revealedSnapshot()->snapshot);

    expect($revealed->pluck('email')->all())->toBe(['ada@example.com', 'ada@lovelace.example'])
        ->and($revealed->pluck('name')->all())->toBe(['Ada', 'Ada']);
});

it('records encrypted personal data on every event', function (): void {
    $customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada', 'email' => null]);
    $customer->update(['number' => 'C-2']);
    $customer->delete();
    $customer->restore();
    $customer->recordIntegritySnapshot();
    $customer->forceDelete();

    $events = $customer->integrityVersions()->get();

    expect($events->pluck('event')->all())->toBe(['created', 'updated', 'deleted', 'restored', 'snapshot', 'force_deleted'])
        ->and($events->every(fn (Version $version): bool => isset($version->snapshot['name']['@encrypted'])))->toBeTrue()
        ->and(DB::table('integrity_versions')->where('snapshot', 'like', '%Ada%')->count())->toBe(0);
});

it('records only anonymized values after the subject was shredded', function (): void {
    $customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada', 'email' => 'ada@example.com']);
    app(SubjectKeys::class)->shred($customer->getMorphClass().':'.$customer->getKey());

    expect(fn () => $customer->update(['number' => 'C-2']))->toThrow(ShreddedSubjectException::class, 'email, name');

    $customer->refresh()->update(['name' => 'deleted', 'email' => null]);

    expect($customer->integrityVersions()->get()->last()->snapshot)->toMatchArray(['name' => 'deleted', 'email' => null]);
});

it('rolls the model change back when its personal data cannot be recorded', function (): void {
    $customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada', 'email' => null]);
    app(SubjectKeys::class)->shred($customer->getMorphClass().':'.$customer->getKey());

    try {
        $customer->update(['number' => 'C-2']);
    } catch (ShreddedSubjectException) {
    }

    expect($customer->fresh()->number)->toBe('C-1');
});

it('uses the model itself when it names no subject', function (): void {
    $order = Order::query()->create(['customer_id' => null, 'number' => 'O-1', 'shipping_name' => 'Bob', 'total' => '1.00']);

    expect(DB::table('integrity_subject_keys')->value('subject'))->toBe($order->getMorphClass().':'.$order->getKey());
});

it('rejects personal attributes that are excluded from snapshots', function (): void {
    $customer = new class extends Customer
    {
        protected $table = 'customers';

        protected array $integrityExcept = ['updated_at', 'email'];
    };

    $customer->newQuery()->create(['number' => 'C-1', 'name' => 'Ada', 'email' => 'x']);
})->throws(IntegrityConfigurationException::class, 'email');

it('records the creation of a subject key in the chain, without the key', function (): void {
    $customer = Customer::query()->create(['number' => 'C-1', 'name' => 'Ada', 'email' => null]);

    $keyVersion = Version::query()->where('versionable_type', 'model-integrity.subject')->sole();

    expect($keyVersion->event)->toBe('created')
        ->and($keyVersion->sequence)->toBeLessThan($customer->integrityVersions()->sole()->sequence)
        ->and($keyVersion->snapshot)->toBe(['id' => $keyVersion->versionable_id, 'shredded_at' => null, 'subject' => $customer->getMorphClass().':'.$customer->getKey()])
        ->and(app(IntegrityChecker::class)->checkAll()->passes())->toBeTrue();
});
