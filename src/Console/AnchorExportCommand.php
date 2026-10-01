<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\ExportsProofs;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Models\AnchorProof;
use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;

class AnchorExportCommand extends Command
{
    protected $signature = 'model-integrity:anchor-export
        {anchor : ID of the anchor}
        {directory : Directory to write the statement and its proofs to}';

    protected $description = 'Write an anchor statement and its proof files, to check them with standard tools';

    public function handle(AnchorManager $drivers, Filesystem $files): int
    {
        $id = $this->argument('anchor');
        $anchor = is_numeric($id) ? AnchorRecord::query()->find((int) $id) : null;

        if ($anchor === null) {
            $this->error('No anchor with ID ['.(is_scalar($id) ? $id : '').'] exists.');

            return self::INVALID;
        }

        $directory = rtrim((string) $this->stringArgument('directory'), '/\\');
        $statement = $anchor->statement();
        $files->ensureDirectoryExists($directory);
        $files->put($directory.'/'.$statement->fileName(), $statement->canonical());
        $this->line("Statement: {$directory}/{$statement->fileName()}");

        /** @var array<string, AnchorProof> $latest */
        $latest = $anchor->proofs->keyBy('driver')->all();

        foreach ($latest as $name => $proof) {
            try {
                $driver = $drivers->driver($name);
            } catch (InvalidArgumentException|IntegrityConfigurationException) {
                continue;
            }

            if ($driver instanceof ExportsProofs && ($contents = $proof->contents()) !== null) {
                $path = "{$directory}/{$statement->fileName()}.{$driver->proofFileExtension()}";
                $files->put($path, $contents);
                $this->line("[{$name}] proof: {$path}");

                match ($driver->proofFileExtension()) {
                    'ots' => $this->line("  check with: ots verify {$path}"),
                    'tsr' => $this->line("  check with: openssl ts -verify -in {$path} -data {$directory}/{$statement->fileName()} -CAfile <CA certificates of the TSA>"),
                    default => null,
                };
            }
        }

        return self::SUCCESS;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : null;
    }
}
