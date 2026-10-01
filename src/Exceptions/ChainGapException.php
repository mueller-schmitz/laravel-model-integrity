<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use RuntimeException;

class ChainGapException extends RuntimeException
{
    public static function missingVersion(int $sequence): self
    {
        return new self("Version {$sequence} of the global chain is missing; nothing was anchored. Run model-integrity:verify.");
    }
}
