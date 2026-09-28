<?php

declare(strict_types=1);

/*
 * A writer process for the concurrency tests. Boots its own application on
 * the database configured by the DB_* environment variables and writes
 * through Eloquent like an application would.
 *
 * Usage: php worker.php <mode> <worker> <writes> <shared model id>
 *
 * Modes:
 * - models:    creates and updates an own invoice, updates a shared invoice
 * - relations: attaches tags to a shared post and records the relation;
 *              this changes no row of the post itself
 */

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\ModelIntegrityServiceProvider;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Post;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Tag;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

[, $mode, $worker, $writes, $sharedId] = $argv;

$app = Application::create(options: [
    'load_environment_variables' => false,
    'extra' => ['providers' => [ModelIntegrityServiceProvider::class], 'dont-discover' => ['*']],
]);

$app['config']->set('database.default', getenv('DB_CONNECTION') ?: 'sqlite');
$app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

try {
    if ($mode === 'models') {
        $own = Invoice::query()->create(['number' => "W{$worker}", 'total' => '0.00']);

        for ($i = 1; $i <= (int) $writes; $i++) {
            $own->update(['total' => number_format($i, 2, '.', '')]);

            // All workers change the same model and compete for its next version.
            Invoice::query()->findOrFail((int) $sharedId)->update(['note' => "worker {$worker} write {$i}"]);
        }
    } elseif ($mode === 'deletes') {
        // $sharedId holds comma-separated ids; every worker updates all of them
        // and finally deletes its own, racing with the others' updates.
        $ids = array_map(intval(...), explode(',', $sharedId));

        for ($i = 1; $i <= (int) $writes; $i++) {
            foreach ($ids as $id) {
                Invoice::query()->find($id)?->update(['note' => "worker {$worker} write {$i}"]);
            }
        }

        Invoice::query()->find($ids[(int) $worker - 1])?->delete();
    } else {
        $post = Post::query()->findOrFail((int) $sharedId);

        for ($i = 1; $i <= (int) $writes; $i++) {
            $tag = Tag::query()->create(['name' => "w{$worker}-{$i}"]);

            DB::transaction(function () use ($post, $tag): void {
                $post->tags()->attach($tag);
                $post->recordRelation('tags');
            });
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "worker {$worker}: ".$e::class.': '.$e->getMessage()."\n");

    exit(1);
}

echo "worker {$worker}: ok\n";
