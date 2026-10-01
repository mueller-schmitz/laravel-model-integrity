<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Contracts;

use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;

/**
 * An anchor whose proofs are completed later, e.g. once an OpenTimestamps
 * calendar has committed to Bitcoin. model-integrity:anchor-upgrade stores an
 * upgraded proof as a new row; the previous one stays.
 */
interface UpgradesProofs
{
    /**
     * The completed proof, or null if there is nothing new yet. An upgraded
     * proof must contain the previous one: it adds to it and never replaces it.
     */
    public function upgrade(AnchorStatement $statement, string $proof): ?string;
}
