<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Verification;

enum IntegrityErrorType: string
{
    /** The stored hash does not match the stored content, or the hash format is unknown. */
    case HashMismatch = 'hash_mismatch';

    /** prev_hash or global_prev_hash does not reference the preceding version. */
    case BrokenChain = 'broken_chain';

    /** The version numbers of a model are not consecutive from 1. */
    case VersionGap = 'version_gap';

    /** The global sequence is not consecutive from 1, i.e. versions were removed. */
    case SequenceGap = 'sequence_gap';

    /** The chain head does not match the last version, e.g. its end was cut off. */
    case TruncatedChain = 'truncated_chain';

    /** The current model row does not match the last recorded snapshot. */
    case StateDrift = 'state_drift';

    /** Versions exist whose model class is missing, untracked or recorded under a former morph class. */
    case Unverifiable = 'unverifiable';
}
