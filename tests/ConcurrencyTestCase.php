<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Runs without the per-test transaction: parallel worker processes use their
 * own connections and can only see committed data. The database is migrated
 * freshly for every test instead.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    public function refreshDatabase(): void
    {
        $this->beforeRefreshingDatabase();

        $this->artisan('migrate:fresh', $this->migrateFreshUsing());
        $this->app?->make(Kernel::class)->setArtisan(null);

        // The next regular test migrates again and starts from a clean state.
        RefreshDatabaseState::$migrated = false;

        $this->afterRefreshingDatabase();
    }

    protected function appendOnlyTriggers(): bool
    {
        return true;
    }
}
