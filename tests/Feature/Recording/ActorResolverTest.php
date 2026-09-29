<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Auth;
use MuellerSchmitz\ModelIntegrity\ModelIntegrity;
use MuellerSchmitz\ModelIntegrity\Recording\ActorResolver;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\User;

beforeEach(function (): void {
    $this->resolver = app(ActorResolver::class);
});

it('resolves no actor by default', function (): void {
    expect($this->resolver->resolve())->toBe(['type' => null, 'id' => null]);
});

it('resolves the authenticated user', function (): void {
    $user = User::query()->create(['name' => 'Anna']);
    Auth::login($user);

    expect($this->resolver->resolve())->toBe(['type' => $user->getMorphClass(), 'id' => (string) $user->getKey()]);
});

it('checks the configured guards in order', function (): void {
    config([
        'auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'],
        'auth.providers.users.model' => User::class,
        'model-integrity.actor.guards' => ['web', 'admin'],
    ]);
    $admin = User::query()->create(['name' => 'Admin']);

    Auth::guard('admin')->login($admin);

    expect(app(ActorResolver::class)->resolve())->toBe(['type' => $admin->getMorphClass(), 'id' => (string) $admin->getKey()]);
});

it('ignores guards that are not configured for the actor', function (): void {
    config([
        'auth.guards.admin' => ['driver' => 'session', 'provider' => 'users'],
        'auth.providers.users.model' => User::class,
    ]);

    Auth::guard('admin')->login(User::query()->create(['name' => 'Admin']));

    expect(app(ActorResolver::class)->resolve())->toBe(['type' => null, 'id' => null]);
});

it('prefers an explicit actor over the authenticated user', function (): void {
    Auth::login(User::query()->create(['name' => 'Anna']));
    $system = User::query()->create(['name' => 'Importer']);

    $this->resolver->actingAs($system);

    expect($this->resolver->resolve())->toBe(['type' => $system->getMorphClass(), 'id' => (string) $system->getKey()]);
});

it('accepts a string label as actor', function (): void {
    $this->resolver->actingAs('system');

    expect($this->resolver->resolve())->toBe(['type' => 'system', 'id' => null]);
});

it('restores the previous actor after a callback', function (): void {
    $this->resolver->actingAs('outer');

    $result = ModelIntegrity::actingAs('inner', function (): array {
        return app(ActorResolver::class)->resolve();
    });

    expect($result)->toBe(['type' => 'inner', 'id' => null])
        ->and($this->resolver->resolve())->toBe(['type' => 'outer', 'id' => null]);
});

it('restores the previous actor when the callback throws', function (): void {
    try {
        ModelIntegrity::actingAs('inner', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect($this->resolver->resolve())->toBe(['type' => null, 'id' => null]);
});

it('forgets the actor', function (): void {
    $this->resolver->actingAs('system');
    $this->resolver->forget();

    expect($this->resolver->resolve())->toBe(['type' => null, 'id' => null]);
});

it('forgets the actor after each queue job', function (string $event): void {
    ModelIntegrity::actingAs('job-actor');

    event(new $event('database', Mockery::mock(Job::class), new RuntimeException));

    expect($this->resolver->resolve())->toBe(['type' => null, 'id' => null]);
})->with([JobProcessed::class, JobFailed::class]);
