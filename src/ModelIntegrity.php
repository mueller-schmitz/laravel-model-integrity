<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity;

use Illuminate\Database\Eloquent\Model;
use MuellerSchmitz\ModelIntegrity\Recording\ActorResolver;

/**
 * Entry point for configuring how changes are recorded.
 */
final class ModelIntegrity
{
    /**
     * Records changes as made by the given actor, e.g. in queue jobs or
     * console commands where no user is authenticated.
     *
     * @template TResult
     *
     * @param  (callable(): TResult)|null  $callback
     * @return TResult|null
     */
    public static function actingAs(Model|string|null $actor, ?callable $callback = null): mixed
    {
        return app(ActorResolver::class)->actingAs($actor, $callback);
    }
}
