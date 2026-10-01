<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Reads the version hashes of a range of the global sequence in chunks.
 */
class VersionHashes
{
    private const int CHUNK = 1000;

    /**
     * When the last version of the range was recorded, in UTC.
     */
    public function latestCreatedAt(int $from, int $to): ?CarbonImmutable
    {
        $configured = config('model-integrity.connection');
        $latest = DB::connection(is_string($configured) ? $configured : null)
            ->table(Config::string('model-integrity.tables.versions'))
            ->whereBetween('sequence', [$from, $to])
            ->max('created_at');

        return is_string($latest) ? new CarbonImmutable($latest, 'UTC') : null;
    }

    /**
     * The stored hashes of the range in sequence order, keyed by sequence.
     * Missing sequences are skipped; callers compare the count.
     *
     * @return Generator<int, string>
     */
    public function between(int $from, int $to): Generator
    {
        $configured = config('model-integrity.connection');
        $connection = DB::connection(is_string($configured) ? $configured : null);
        $table = Config::string('model-integrity.tables.versions');

        for ($start = $from; $start <= $to; $start += self::CHUNK) {
            $rows = $connection->table($table)
                ->whereBetween('sequence', [$start, min($to, $start + self::CHUNK - 1)])
                ->orderBy('sequence')
                ->get(['sequence', 'hash']);

            foreach ($rows as $row) {
                if (is_numeric($row->sequence) && is_string($row->hash)) {
                    yield (int) $row->sequence => $row->hash;
                }
            }
        }
    }
}
