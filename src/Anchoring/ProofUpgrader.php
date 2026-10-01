<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\UpgradesProofs;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Models\AnchorProof;
use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;
use Throwable;

/**
 * Completes pending proofs, e.g. OpenTimestamps proofs once the calendars
 * have committed to Bitcoin. An upgraded proof is added as a new row; the
 * previous row stays, and the latest row of a driver counts.
 */
class ProofUpgrader
{
    public function __construct(
        private readonly AnchorManager $drivers,
    ) {}

    /**
     * @return array{upgraded: int, failures: list<string>}
     */
    public function upgrade(): array
    {
        $upgraded = 0;
        $failures = [];

        foreach (AnchorRecord::query()->with('proofs')->lazyById(200) as $anchor) {
            /** @var array<string, AnchorProof> $latest */
            $latest = $anchor->proofs->keyBy('driver')->all();

            foreach ($latest as $name => $proof) {
                try {
                    $driver = $this->drivers->driver($name);
                } catch (InvalidArgumentException|IntegrityConfigurationException) {
                    continue;
                }

                if (! $driver instanceof UpgradesProofs) {
                    continue;
                }

                try {
                    $contents = $proof->contents() ?? throw new InvalidArgumentException('The stored proof is not valid base64.');
                    $completed = $driver->upgrade($anchor->statement(), $contents);
                } catch (Throwable $e) {
                    $failures[] = "[{$name}] anchor #{$anchor->id}: {$e->getMessage()}";

                    continue;
                }

                if ($completed !== null) {
                    $this->store($anchor, $name, $completed);
                    $upgraded++;
                }
            }
        }

        return ['upgraded' => $upgraded, 'failures' => $failures];
    }

    private function store(AnchorRecord $anchor, string $driver, string $proof): void
    {
        $configured = config('model-integrity.connection');

        DB::connection(is_string($configured) ? $configured : null)
            ->table(Config::string('model-integrity.tables.anchor_proofs', 'integrity_anchor_proofs'))
            ->insert([
                'anchor_id' => $anchor->id,
                'driver' => $driver,
                'proof' => base64_encode($proof),
                'created_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            ]);
    }
}
