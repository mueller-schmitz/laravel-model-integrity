<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use MuellerSchmitz\ModelIntegrity\Anchoring\ProofUpgrader;

class AnchorUpgradeCommand extends Command
{
    protected $signature = 'model-integrity:anchor-upgrade';

    protected $description = 'Complete pending anchor proofs, e.g. OpenTimestamps proofs once they are in Bitcoin';

    public function handle(ProofUpgrader $upgrader): int
    {
        ['upgraded' => $upgraded, 'failures' => $failures] = $upgrader->upgrade();

        foreach ($failures as $failure) {
            $this->error($failure);
        }

        $this->info($upgraded === 0 ? 'No proof was upgraded.' : "Upgraded {$upgraded} ".($upgraded === 1 ? 'proof.' : 'proofs.'));

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
