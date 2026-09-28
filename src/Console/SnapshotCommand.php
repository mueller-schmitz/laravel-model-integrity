<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use MuellerSchmitz\ModelIntegrity\Console\Concerns\ResolvesModelClasses;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\ModelOptions;
use MuellerSchmitz\ModelIntegrity\Recording\SnapshotBuilder;
use MuellerSchmitz\ModelIntegrity\Recording\VersionRecorder;

class SnapshotCommand extends Command
{
    use ResolvesModelClasses;

    protected $signature = 'model-integrity:snapshot
        {--model= : Model class or morph alias}
        {--all : Every model type with recorded versions}
        {--reason= : Reason stored with the recorded versions}';

    protected $description = 'Record a new snapshot for models whose last snapshot is outdated, e.g. after a schema change';

    public function handle(SnapshotBuilder $snapshots, VersionRecorder $recorder): int
    {
        $model = $this->stringOption('model');

        if ($model === null && $this->option('all') !== true) {
            $this->error('Pass --model or --all.');

            return self::INVALID;
        }

        if ($model !== null) {
            $class = $this->resolveModelClass($model);

            if ($class === null) {
                $this->error("[{$model}] is no model class or morph alias using the HasIntegrity trait.");

                return self::INVALID;
            }

            $classes = [$class];
        } else {
            $classes = $this->recordedModelClasses();
        }

        $reason = $this->stringOption('reason');
        $recorded = 0;

        foreach ($classes as $class) {
            $recorded += $this->snapshotType($class, $snapshots, $recorder, $reason);
        }

        $this->info($recorded.' '.($recorded === 1 ? 'snapshot' : 'snapshots').' recorded.');

        return self::SUCCESS;
    }

    /**
     * Records a version for every row without one ('created'), and for every
     * model whose last snapshot has an older schema version or no longer
     * matches the row ('snapshot').
     *
     * @param  class-string<Model>  $class
     */
    private function snapshotType(string $class, SnapshotBuilder $snapshots, VersionRecorder $recorder, ?string $reason): int
    {
        $prototype = new $class;
        $options = ModelOptions::of($prototype);
        $recorded = 0;

        foreach ($class::query()->withoutGlobalScopes()->lazyById(500, $prototype->getKeyName()) as $model) {
            $key = $model->getKey();
            $last = Version::query()
                ->where('versionable_type', $model->getMorphClass())
                ->where('versionable_id', is_scalar($key) ? (string) $key : '')
                ->orderByDesc('version')
                ->first();

            $snapshot = fn (): array => $snapshots->build($model, $options->except, $options->relations);

            if ($last === null) {
                $recorder->record($model, 'created', $snapshot, $options->schemaVersion, $reason);
                $recorded++;
            } elseif ($last->schema_version < $options->schemaVersion || $snapshot() !== $last->snapshot) {
                $recorder->record($model, 'snapshot', $snapshot, $options->schemaVersion, $reason);
                $recorded++;
            }
        }

        return $recorded;
    }
}
