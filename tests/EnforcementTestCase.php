<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests;

use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Runs with the append-only triggers installed.
 *
 * The regular test suite migrates without triggers so tests can tamper with
 * versions via SQL. Creating triggers is DDL, which commits implicitly on
 * MySQL, so it cannot happen inside the per-test transaction: the database is
 * migrated freshly for these tests instead.
 */
abstract class EnforcementTestCase extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The next regular test migrates again, without triggers.
        RefreshDatabaseState::$migrated = false;
    }

    protected function appendOnlyTriggers(): bool
    {
        return true;
    }
}
