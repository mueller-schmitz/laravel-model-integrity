<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityError;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;

class VerifyCommand extends Command
{
    protected $signature = 'model-integrity:verify
        {--model= : Model class or morph alias to check (default: everything)}
        {--id= : Key of a single model, requires --model}
        {--fail-fast : Stop after the first model with violations}';

    protected $description = 'Verify recorded versions, the hash chains and the current model state';

    public function handle(IntegrityChecker $checker): int
    {
        $model = $this->stringOption('model');
        $id = $this->stringOption('id');
        $failFast = $this->option('fail-fast') === true;

        if ($id !== null && $model === null) {
            $this->error('--id requires --model.');

            return self::INVALID;
        }

        if ($model !== null) {
            $class = $this->resolveClass($model);

            if ($class === null) {
                $this->error("[{$model}] is no model class or morph alias using the HasIntegrity trait.");

                return self::INVALID;
            }

            $result = $id !== null
                ? $checker->checkModel($checker->findModel($class, $this->castKey($class, $id)))
                : $checker->checkType($class, $failFast);
        } else {
            $result = $checker->checkAll($failFast);
        }

        return $this->report($result);
    }

    private function report(IntegrityResult $result): int
    {
        $versions = $result->checkedVersions().' '.($result->checkedVersions() === 1 ? 'version' : 'versions');

        if ($result->passes()) {
            $this->info("No violations. Checked {$versions}.");

            return self::SUCCESS;
        }

        $this->table(
            ['Type', 'Model', 'Version', 'Sequence', 'Message'],
            $result->errors()->map(fn (IntegrityError $error): array => [
                $error->type->value,
                $error->versionableType === null ? '-' : $error->versionableType.'#'.$error->versionableId,
                $error->version ?? '-',
                $error->sequence ?? '-',
                $error->message,
            ])->all(),
        );

        $count = $result->errors()->count();
        $this->error($count.' '.($count === 1 ? 'violation' : 'violations')." found. Checked {$versions}.");

        return self::FAILURE;
    }

    /**
     * @return class-string<Model>|null
     */
    private function resolveClass(string $model): ?string
    {
        $class = Relation::getMorphedModel($model) ?? $model;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)
            || ! in_array(HasIntegrity::class, class_uses_recursive($class), true)) {
            return null;
        }

        return $class;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function castKey(string $class, string $id): int|string
    {
        return in_array((new $class)->getKeyType(), ['int', 'integer'], true) ? (int) $id : $id;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        // Artisan::call() passes numbers as int; the command line always passes strings.
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
