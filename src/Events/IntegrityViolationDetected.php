<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

/**
 * Dispatched when a check finds at least one violation. $model is set for
 * checks of a single model and null for type-wide or global checks.
 *
 * After checkAll() the result can hold many errors. Queued listeners should
 * serialize what they need (e.g. the error count and the first errors as
 * strings) instead of the whole event.
 */
class IntegrityViolationDetected
{
    use Dispatchable;

    public function __construct(
        public readonly IntegrityResult $result,
        public readonly ?Model $model = null,
    ) {}
}
