<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Verification;

use Illuminate\Support\Collection;

final class IntegrityResult
{
    /** @var Collection<int, IntegrityError> */
    private readonly Collection $errors;

    /**
     * @param  iterable<int, IntegrityError>  $errors
     * @param  int|null  $lastValidVersion  highest version n for which versions 1..n are valid
     */
    public function __construct(
        iterable $errors,
        private readonly int $checkedVersions,
        private readonly ?int $lastValidVersion,
    ) {
        $this->errors = Collection::make($errors)
            // Prefer the error that invalidates a specific version.
            ->sortBy(fn (IntegrityError $error): int => $error->version === null ? 1 : 0)
            ->unique(fn (IntegrityError $error): string => $error->key())
            ->sortBy(fn (IntegrityError $error): int => $error->sequence ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * Combines the results of several checks; duplicate violations are merged.
     *
     * @param  iterable<int, IntegrityResult>  $results
     */
    public static function combine(iterable $results, int $checkedVersions): self
    {
        return new self(
            Collection::make($results)->flatMap(fn (IntegrityResult $result): Collection => $result->errors()),
            $checkedVersions,
            null,
        );
    }

    public function passes(): bool
    {
        return $this->errors->isEmpty();
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    /**
     * @return Collection<int, IntegrityError>
     */
    public function errors(): Collection
    {
        return $this->errors;
    }

    public function checkedVersions(): int
    {
        return $this->checkedVersions;
    }

    public function lastValidVersion(): ?int
    {
        return $this->lastValidVersion;
    }
}
