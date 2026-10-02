<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Shredding;

/**
 * The key that encrypts the personal data of one data subject.
 */
final readonly class SubjectKey
{
    public function __construct(
        /** ULID, written next to every value encrypted with this key */
        public string $id,
        /** 32 raw bytes */
        public string $key,
    ) {}
}
