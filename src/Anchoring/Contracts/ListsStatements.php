<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Contracts;

use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;

/**
 * An anchor that can list what it holds. Verification compares the list with
 * the anchors in the database, so deleting anchor rows is detected as well.
 */
interface ListsStatements
{
    /**
     * Every anchored statement in sequence order, with where it was found.
     * Entries that cannot be read have no statement.
     *
     * @return iterable<array{location: string, statement: AnchorStatement|null}>
     */
    public function statements(): iterable;
}
