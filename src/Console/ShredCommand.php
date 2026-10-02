<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use MuellerSchmitz\ModelIntegrity\Shredding\Subjects;

class ShredCommand extends Command
{
    protected $signature = 'model-integrity:shred
        {subject : Model class or morph alias of the data subject}
        {id : Key of the data subject}
        {--reason= : Reason recorded in the chain, e.g. the number of the erasure request}';

    protected $description = 'Shred the key of a data subject: its personal data in all versions becomes unreadable';

    public function handle(Subjects $subjects): int
    {
        $type = $this->argument('subject');
        $id = $this->argument('id');
        // Artisan::call() passes numbers as they are.
        $id = is_int($id) ? (string) $id : $id;

        if (! is_string($type) || ! is_string($id) || $type === '' || $id === '') {
            $this->error('Name the data subject by model class or morph alias and key.');

            return self::INVALID;
        }

        if (class_exists($type) && is_subclass_of($type, Model::class)) {
            $type = (new $type)->getMorphClass();
        }

        $subject = "{$type}:{$id}";
        $reason = $this->option('reason');

        if ($subjects->isShredded($subject)) {
            $this->info("The key of [{$subject}] was already shredded.");

            return self::SUCCESS;
        }

        $subjects->shred($subject, is_string($reason) && $reason !== '' ? $reason : null);

        $this->info("Shredded the key of [{$subject}]. Anonymize its personal data in the application's tables; model-integrity:verify reports what is left.");

        return self::SUCCESS;
    }
}
