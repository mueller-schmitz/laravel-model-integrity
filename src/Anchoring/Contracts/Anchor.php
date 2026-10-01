<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Contracts;

use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorVerification;

/**
 * Anchors statements about the chain outside the database.
 */
interface Anchor
{
    /**
     * Anchors the statement and returns the proof to keep. The proof may be
     * binary; it is stored base64-encoded.
     *
     * @throws \Throwable when the statement could not be anchored
     */
    public function submit(AnchorStatement $statement): string;

    /**
     * Checks a proof against the statement recomputed from the database.
     * Problems are reported as an invalid verification, not thrown.
     */
    public function verify(AnchorStatement $statement, string $proof): AnchorVerification;
}
