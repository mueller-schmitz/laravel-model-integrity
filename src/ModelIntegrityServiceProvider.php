<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity;

use Illuminate\Support\ServiceProvider;

class ModelIntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/model-integrity.php', 'model-integrity');
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
