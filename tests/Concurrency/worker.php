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
 * - anchor:    runs model-integrity:anchor <writes> times on the anchor disk
 * - orders:    creates <writes> orders with personal data of the shared customer
 */

use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Anchoring\Anchorer;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;
use MuellerSchmitz\ModelIntegrity\ModelIntegrityServiceProvider;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Order;
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
$app['config']->set('filesystems.disks.integrity', ['driver' => 'local', 'root' => getenv('MI_FILES_ROOT') ?: sys_get_temp_dir().'/mi-files']);
$app['config']->set('model-integrity.files.disk', 'integrity');
$app['config']->set('filesystems.disks.anchors', ['driver' => 'local', 'root' => getenv('MI_ANCHORS_ROOT') ?: sys_get_temp_dir().'/mi-anchors']);
$app['config']->set('model-integrity.anchors', ['drivers' => ['disk'], 'disk' => ['disk' => 'anchors', 'path' => 'statements']]);

try {
    if ($mode === 'models') {
        $own = Invoice::query()->create(['number' => "W{$worker}", 'total' => '0.00']);

        for ($i = 1; $i <= (int) $writes; $i++) {
            $own->update(['total' => number_format($i, 2, '.', '')]);

            // All workers change the same model and compete for its next version.
            Invoice::query()->findOrFail((int) $sharedId)->update(['note' => "worker {$worker} write {$i}"]);
        }
    } elseif ($mode === 'orders') {
        for ($i = 1; $i <= (int) $writes; $i++) {
            Order::query()->create(['customer_id' => (int) $sharedId, 'number' => "W{$worker}-{$i}", 'shipping_name' => "Name {$worker}", 'total' => '1.00']);
        }
    } elseif ($mode === 'anchor') {
        for ($i = 1; $i <= (int) $writes; $i++) {
            app(Anchorer::class)->anchor();
            usleep(random_int(0, 20_000));
        }
    } elseif ($mode === 'store-one') {
        // Stores the content given as the last argument once.
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, (string) $sharedId);
        rewind($stream);
        IntegrityFiles::store($stream);
        fclose($stream);
    } elseif ($mode === 'files') {
        // Every worker stores the same shared content and its own content.
        for ($i = 1; $i <= (int) $writes; $i++) {
            foreach (['shared content', "worker {$worker} write {$i}"] as $contents) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $contents);
                rewind($stream);
                IntegrityFiles::store($stream);
                fclose($stream);
            }
        }
    } elseif ($mode === 'encrypted-files') {
        // All workers store files of the same new subject and compete for its key.
        for ($i = 1; $i <= (int) $writes; $i++) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, "worker {$worker} write {$i}");
            rewind($stream);
            IntegrityFiles::store($stream, subject: (string) $sharedId);
            fclose($stream);
        }
    } elseif ($mode === 'deletes') {
        // $sharedId holds comma-separated ids; every worker updates all of them
        // and finally deletes its own, racing with the others' updates.
        $ids = array_map(intval(...), explode(',', $sharedId));

        for ($i = 1; $i <= (int) $writes; $i++) {
            foreach ($ids as $id) {
                // Lock the row first: a plain find() may return a model whose row
                // another worker deletes before the update runs.
                DB::transaction(fn () => Invoice::query()->lockForUpdate()->find($id)?->update(['note' => "worker {$worker} write {$i}"]));
            }
        }

        DB::transaction(fn () => Invoice::query()->lockForUpdate()->find($ids[(int) $worker - 1])?->delete());
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
