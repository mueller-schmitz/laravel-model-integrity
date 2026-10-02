<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Shredding;

/**
 * A snapshot with its personal attributes decrypted where their key still exists.
 */
final readonly class RevealedSnapshot
{
    /**
     * @param  array<string, mixed>  $snapshot  shredded attributes are null
     * @param  list<string>  $shredded  attributes whose key was shredded, sorted
     */
    public function __construct(
        public array $snapshot,
        public array $shredded,
    ) {}
}
