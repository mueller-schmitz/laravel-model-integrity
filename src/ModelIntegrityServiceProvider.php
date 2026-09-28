<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity;

use Illuminate\Support\ServiceProvider;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;

class ModelIntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/model-integrity.php', 'model-integrity');

        $this->app->singleton(CanonicalSerializer::class);
        $this->app->singleton(Hasher::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/model-integrity.php' => config_path('model-integrity.php'),
        ], 'model-integrity-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'model-integrity-migrations');
    }
}
