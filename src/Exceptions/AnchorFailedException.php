<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use RuntimeException;
use Throwable;

class AnchorFailedException extends RuntimeException
{
    /**
     * @param  array<string, Throwable>  $failures  per driver
     */
    public function __construct(public readonly array $failures)
    {
        $messages = array_map(
            fn (string $driver, Throwable $e): string => "[{$driver}] {$e->getMessage()}",
            array_keys($failures),
            $failures,
        );

        parent::__construct('No anchor driver succeeded: '.implode('; ', $messages), previous: reset($failures) ?: null);
    }
}
