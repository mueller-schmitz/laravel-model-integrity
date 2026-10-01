<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Exceptions\AnchorFailedException;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;
use MuellerSchmitz\ModelIntegrity\Recording\ChainName;
use RuntimeException;
use Throwable;

/**
 * Anchors the versions recorded since the last anchor.
 *
 * Runs are serialized by a lock on the anchors head row; recording writes are
 * not blocked. Versions get their sequence under the global head lock and
 * commit in that order, so every version up to the committed global head is
 * visible.
 *
 * Drivers are called inside the transaction. A statement a driver keeps
 * although the transaction rolls back is harmless: verification checks every
 * statement against the versions it covers.
 */
class Anchorer
{
    public function __construct(
        private readonly AnchorManager $drivers,
        private readonly MerkleTree $tree,
        private readonly VersionHashes $versions,
    ) {}

    /**
     * @param  list<string>|null  $driverNames  null for the configured drivers
     *
     * @throws AnchorFailedException when every driver failed; nothing is kept then
     * @throws InvalidArgumentException for an unknown driver
     * @throws IntegrityConfigurationException without drivers or anchors head
     */
    public function anchor(?array $driverNames = null): AnchorRun
    {
        /** @var array<string, Anchor> $drivers resolved first, so a misconfiguration fails before anything is written */
        $drivers = [];

        foreach ($driverNames ?? $this->drivers->enabledDrivers() as $name) {
            $drivers[$name] = $this->drivers->driver($name);
        }

        if ($drivers === []) {
            throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.drivers', 'at least one driver name');
        }

        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $drivers): AnchorRun {
            $head = $connection->table($this->table('heads'))
                ->where('chain', ChainName::ANCHORS)
                ->lockForUpdate()
                ->first(['sequence', 'hash']);

            if ($head === null) {
                throw IntegrityConfigurationException::headMissing(ChainName::ANCHORS);
            }

            $from = (is_numeric($head->sequence) ? (int) $head->sequence : 0) + 1;
            $global = $connection->table($this->table('heads'))->where('chain', ChainName::GLOBAL)->value('sequence');
            $to = is_numeric($global) ? (int) $global : 0;

            if ($to < $from) {
                return new AnchorRun(null);
            }

            $statement = new AnchorStatement(
                AnchorStatement::FORMAT,
                $from,
                $to,
                (string) $this->tree->root($this->contiguous($from, $to)),
                is_string($head->hash) ? $head->hash : null,
            );

            $proofs = [];
            $failures = [];

            foreach ($drivers as $name => $driver) {
                try {
                    $proofs[$name] = $driver->submit($statement);
                } catch (Throwable $e) {
                    $failures[$name] = $e;
                }
            }

            if ($proofs === []) {
                throw new AnchorFailedException($failures);
            }

            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

            $id = $connection->table($this->table('anchors'))->insertGetId([
                'anchor_format' => $statement->format,
                'from_sequence' => $statement->fromSequence,
                'to_sequence' => $statement->toSequence,
                'merkle_root' => $statement->merkleRoot,
                'prev_digest' => $statement->prevDigest,
                'digest' => $statement->digest(),
                'created_at' => $now,
            ]);

            $connection->table($this->table('anchor_proofs'))->insert(array_map(
                fn (string $name, string $proof): array => [
                    'anchor_id' => $id,
                    'driver' => $name,
                    'proof' => base64_encode($proof),
                    'created_at' => $now,
                ],
                array_keys($proofs),
                $proofs,
            ));

            $connection->table($this->table('heads'))
                ->where('chain', ChainName::ANCHORS)
                ->update(['sequence' => $to, 'hash' => $statement->digest(), 'updated_at' => $now]);

            return new AnchorRun(AnchorRecord::query()->findOrFail($id), $failures);
        });
    }

    /**
     * The version hashes of the range in sequence order. A missing sequence
     * means removed versions; anchoring would attest the tampered chain.
     *
     * @return Generator<int, string>
     */
    private function contiguous(int $from, int $to): Generator
    {
        $expected = $from;

        foreach ($this->versions->between($from, $to) as $sequence => $hash) {
            if ($sequence !== $expected) {
                break;
            }

            $expected++;

            yield $hash;
        }

        if ($expected !== $to + 1) {
            throw new RuntimeException("Version {$expected} of the global chain is missing; nothing was anchored. Run model-integrity:verify.");
        }
    }

    private function connection(): Connection
    {
        $configured = config('model-integrity.connection');

        /** @var Connection */
        return DB::connection(is_string($configured) ? $configured : null);
    }

    private function table(string $name): string
    {
        return Config::string("model-integrity.tables.{$name}");
    }
}
