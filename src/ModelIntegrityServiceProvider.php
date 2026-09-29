<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use MuellerSchmitz\ModelIntegrity\Console\GrantsCommand;
use MuellerSchmitz\ModelIntegrity\Console\InstallCommand;
use MuellerSchmitz\ModelIntegrity\Console\SnapshotCommand;
use MuellerSchmitz\ModelIntegrity\Console\TriggersCommand;
use MuellerSchmitz\ModelIntegrity\Console\VerifyCommand;
use MuellerSchmitz\ModelIntegrity\Files\FileStore;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\Recording\ActorResolver;
use MuellerSchmitz\ModelIntegrity\Recording\SnapshotBuilder;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;

class ModelIntegrityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/model-integrity.php', 'model-integrity');

        $this->app->singleton(CanonicalSerializer::class);
        $this->app->singleton(Hasher::class);
        $this->app->singleton(SnapshotBuilder::class);
        $this->app->scoped(ActorResolver::class);
        $this->app->singleton(IntegrityChecker::class);
        $this->app->singleton(FileStore::class);
    }

    public function boot(): void
    {
        // An actor set inside a job must not leak into the next job of the worker.
        $forgetActor = fn () => $this->app->make(ActorResolver::class)->forget();
        Event::listen(JobProcessed::class, $forgetActor);
        Event::listen(JobFailed::class, $forgetActor);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            GrantsCommand::class,
            InstallCommand::class,
            SnapshotCommand::class,
            TriggersCommand::class,
            VerifyCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/model-integrity.php' => config_path('model-integrity.php'),
        ], 'model-integrity-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'model-integrity-migrations');
    }
}
