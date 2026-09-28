<?php

declare(strict_types=1);

/*
 * A writer process for the concurrency test. Boots its own application on the
 * database configured by the DB_* environment variables and writes through
 * Eloquent like an application would.
 *
 * Usage: php worker.php <worker> <writes> <shared invoice id>
 */

use MuellerSchmitz\ModelIntegrity\ModelIntegrityServiceProvider;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models\Invoice;
use Orchestra\Testbench\Foundation\Application;

require __DIR__.'/../../vendor/autoload.php';

[, $worker, $writes, $sharedId] = $argv;

$app = Application::create(options: [
    'load_environment_variables' => false,
    'extra' => ['providers' => [ModelIntegrityServiceProvider::class], 'dont-discover' => ['*']],
]);

$app['config']->set('database.default', getenv('DB_CONNECTION') ?: 'sqlite');
$app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

try {
    $own = Invoice::query()->create(['number' => "W{$worker}", 'total' => '0.00']);

    for ($i = 1; $i <= (int) $writes; $i++) {
        $own->update(['total' => number_format($i, 2, '.', '')]);

        // All workers change the same model and compete for its next version.
        Invoice::query()->findOrFail((int) $sharedId)->update(['note' => "worker {$worker} write {$i}"]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, "worker {$worker}: ".$e::class.': '.$e->getMessage()."\n");

    exit(1);
}

echo "worker {$worker}: ok\n";
