<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;
use MuellerSchmitz\ModelIntegrity\Models\Version;

trait ResolvesModelClasses
{
    /**
     * A class name or morph alias, if it names a model using HasIntegrity.
     *
     * @return class-string<Model>|null
     */
    protected function resolveModelClass(string $model): ?string
    {
        $class = Relation::getMorphedModel($model) ?? $model;

        return $this->isTrackedClass($class) ? $class : null;
    }

    /**
     * The classes of all recorded model types that still exist and use the trait.
     *
     * @return list<class-string<Model>>
     */
    protected function recordedModelClasses(): array
    {
        $classes = [];

        foreach (Version::query()->distinct()->orderBy('versionable_type')->pluck('versionable_type') as $type) {
            $class = is_string($type) ? $this->resolveModelClass($type) : null;

            if ($class !== null) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @phpstan-assert-if-true class-string<Model> $class
     */
    private function isTrackedClass(string $class): bool
    {
        return class_exists($class)
            && is_subclass_of($class, Model::class)
            && in_array(HasIntegrity::class, class_uses_recursive($class), true);
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        // Artisan::call() passes numbers as int; the command line always passes strings.
        $value = is_int($value) ? (string) $value : $value;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
