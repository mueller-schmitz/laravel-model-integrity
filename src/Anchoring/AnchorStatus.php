<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

enum AnchorStatus: string
{
    /** The proof attests the statement. */
    case Confirmed = 'confirmed';

    /** The proof is valid so far but not yet complete, e.g. awaiting a Bitcoin block. */
    case Pending = 'pending';

    /** The proof does not attest the statement, or cannot be found. */
    case Invalid = 'invalid';
}
