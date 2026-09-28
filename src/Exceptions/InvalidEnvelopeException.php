<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use InvalidArgumentException;

class InvalidEnvelopeException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $missing
     * @param  list<string>  $unknown
     */
    public static function fieldMismatch(int $format, array $missing, array $unknown): self
    {
        $details = array_filter([
            $missing !== [] ? 'missing ['.implode(', ', $missing).']' : null,
            $unknown !== [] ? 'unknown ['.implode(', ', $unknown).']' : null,
        ]);

        return new self("Invalid envelope for hash format {$format}: ".implode(', ', $details).'.');
    }
}
