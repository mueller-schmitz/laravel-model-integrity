<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Contracts;

/**
 * An anchor whose proofs are files that standard tools can check, written
 * next to the statement by model-integrity:anchor-export.
 */
interface ExportsProofs
{
    /**
     * The extension appended to the statement's file name, e.g. "ots".
     */
    public function proofFileExtension(): string;
}
