<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

/**
 * The outcome of an auditor export.
 */
final readonly class ExportResult
{
    /**
     * @param  array{all: IntegrityResult, files: IntegrityResult|null}  $checks
     * @param  int  $headSequence  the global sequence the export covers up to
     * @param  list<string>  $problems  data that could not be exported as it should
     */
    public function __construct(
        public array $checks,
        public int $headSequence,
        public array $problems,
    ) {}

    public function passes(): bool
    {
        return $this->problems === []
            && $this->checks['all']->passes()
            && ($this->checks['files'] === null || $this->checks['files']->passes());
    }
}
