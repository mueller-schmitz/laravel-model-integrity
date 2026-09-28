<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Recording;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Determines who is responsible for a change: an explicitly set actor,
 * otherwise the authenticated user, otherwise nobody.
 */
class ActorResolver
{
    private Model|string|null $actor = null;

    public function __construct(
        private readonly AuthFactory $auth,
    ) {}

    /**
     * Sets the actor. With a callback, the actor applies only while the
     * callback runs and the previous actor is restored afterwards.
     *
     * @template TResult
     *
     * @param  (callable(): TResult)|null  $callback
     * @return TResult|null
     */
    public function actingAs(Model|string|null $actor, ?callable $callback = null): mixed
    {
        if ($callback === null) {
            $this->actor = $actor;

            return null;
        }

        $previous = $this->actor;
        $this->actor = $actor;

        try {
            return $callback();
        } finally {
            $this->actor = $previous;
        }
    }

    public function forget(): void
    {
        $this->actor = null;
    }

    /**
     * @return array{type: string|null, id: string|null}
     */
    public function resolve(): array
    {
        return $this->describe($this->actor ?? $this->auth->guard()->user());
    }

    /**
     * Auth users are not necessarily Eloquent models, e.g. GenericUser of the
     * database auth driver.
     *
     * @return array{type: string|null, id: string|null}
     */
    private function describe(mixed $actor): array
    {
        return match (true) {
            $actor instanceof Model => ['type' => $actor->getMorphClass(), 'id' => $this->key($actor->getKey())],
            is_string($actor) => ['type' => $actor, 'id' => null],
            $actor instanceof Authenticatable => ['type' => $actor::class, 'id' => $this->key($actor->getAuthIdentifier())],
            default => ['type' => null, 'id' => null],
        };
    }

    private function key(mixed $key): ?string
    {
        return is_scalar($key) ? (string) $key : null;
    }
}
