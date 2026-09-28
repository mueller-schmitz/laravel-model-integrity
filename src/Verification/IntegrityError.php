<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Verification;

use Stringable;

/**
 * A single integrity violation.
 *
 * $version is set when the violation invalidates that version of the model;
 * it is null for violations that concern the chain or the current state only.
 */
final readonly class IntegrityError implements Stringable
{
    public function __construct(
        public IntegrityErrorType $type,
        public string $message,
        public ?string $versionableType = null,
        public ?string $versionableId = null,
        public ?int $version = null,
        public ?int $sequence = null,
    ) {}

    /**
     * Identifies the same violation found by different checks (e.g. the model
     * check and the global chain check), which report it with the same message.
     */
    public function key(): string
    {
        return implode('|', [$this->type->value, $this->sequence ?? $this->versionableType.'#'.$this->versionableId, $this->message]);
    }

    public function __toString(): string
    {
        $subject = $this->versionableType === null ? 'chain' : $this->versionableType.'#'.$this->versionableId;
        $location = implode(' ', array_filter([
            $this->version === null ? null : 'v'.$this->version,
            $this->sequence === null ? null : '(sequence '.$this->sequence.')',
        ]));

        return "[{$this->type->value}] {$subject}".($location === '' ? '' : ' '.$location).": {$this->message}";
    }
}
