<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use MuellerSchmitz\ModelIntegrity\Models\Version;

/**
 * Dispatched after the transaction that recorded the version has been committed.
 */
class VersionRecorded
{
    use Dispatchable;

    public function __construct(
        public readonly Version $version,
        public readonly Model $model,
    ) {}
}
