<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Anchoring\Anchorer;
use MuellerSchmitz\ModelIntegrity\Exceptions\AnchorFailedException;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;

class AnchorCommand extends Command
{
    protected $signature = 'model-integrity:anchor
        {--driver=* : Anchor with these drivers instead of the configured ones}';

    protected $description = 'Anchor the versions recorded since the last anchor outside the database';

    public function handle(Anchorer $anchorer): int
    {
        /** @var list<string> $drivers */
        $drivers = array_values(array_filter((array) $this->option('driver'), is_string(...)));

        try {
            $run = $anchorer->anchor($drivers === [] ? null : $drivers);
        } catch (InvalidArgumentException|IntegrityConfigurationException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        } catch (AnchorFailedException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($run->anchor === null) {
            $this->info('Nothing to anchor: no versions were recorded since the last anchor.');

            return self::SUCCESS;
        }

        $anchor = $run->anchor;
        $this->info(sprintf(
            'Anchored sequences %d to %d (digest %s) with %s.',
            $anchor->from_sequence,
            $anchor->to_sequence,
            $anchor->digest,
            $anchor->proofs->pluck('driver')->implode(', '),
        ));

        foreach ($run->failures as $driver => $failure) {
            $this->error("[{$driver}] {$failure->getMessage()}");
        }

        return $run->failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
