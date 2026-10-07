<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use RuntimeException;

/**
 * Personal data of a data subject whose key was shredded cannot be recorded:
 * it would end up unencrypted in the append-only history. Files encrypted
 * with the key cannot be read any more.
 */
class ShreddedSubjectException extends RuntimeException
{
    public static function noKey(string $subject): self
    {
        return new self("The key of data subject [{$subject}] was shredded; its personal data cannot be recorded any more.");
    }

    public static function file(string $sha256): self
    {
        return new self("The key of the data subject of stored file [{$sha256}] was shredded; the file cannot be read any more.");
    }

    /**
     * @param  list<string>  $attributes
     */
    public static function personalData(string $subject, array $attributes): self
    {
        return new self("The key of data subject [{$subject}] was shredded; the attributes [".implode(', ', $attributes).'] must be null or their anonymized value.');
    }
}
