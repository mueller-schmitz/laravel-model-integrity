<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MuellerSchmitz\ModelIntegrity\ModelIntegrityServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            ModelIntegrityServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // 'testing' is Testbench's in-memory SQLite connection. CI sets DB_CONNECTION
        // (mysql, mariadb, pgsql) plus DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD.
        $app['config']->set('database.default', env('DB_CONNECTION', 'testing'));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
