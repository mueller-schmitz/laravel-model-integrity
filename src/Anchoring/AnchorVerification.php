<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use Carbon\CarbonImmutable;

/**
 * The result of checking one proof against its statement.
 */
final readonly class AnchorVerification
{
    public function __construct(
        public AnchorStatus $status,
        public string $message,
        /** When the external party attested the statement, if the proof says so. */
        public ?CarbonImmutable $attestedAt = null,
    ) {}

    public static function confirmed(string $message, ?CarbonImmutable $attestedAt = null): self
    {
        return new self(AnchorStatus::Confirmed, $message, $attestedAt);
    }

    public static function pending(string $message): self
    {
        return new self(AnchorStatus::Pending, $message);
    }

    public static function invalid(string $message): self
    {
        return new self(AnchorStatus::Invalid, $message);
    }
}
