<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;
use Throwable;

/**
 * The outcome of an anchor run.
 */
final readonly class AnchorRun
{
    /**
     * @param  AnchorRecord|null  $anchor  null if there was nothing new to anchor
     * @param  array<string, Throwable>  $failures  drivers that failed, while others succeeded
     */
    public function __construct(
        public ?AnchorRecord $anchor,
        public array $failures = [],
    ) {}
}
