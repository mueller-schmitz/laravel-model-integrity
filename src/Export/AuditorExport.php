<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\ExportsProofs;
use MuellerSchmitz\ModelIntegrity\Anchoring\MerkleLevels;
use MuellerSchmitz\ModelIntegrity\Anchoring\MerkleTree;
use MuellerSchmitz\ModelIntegrity\Anchoring\VersionHashes;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Models\AnchorProof;
use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Recording\ChainName;
use MuellerSchmitz\ModelIntegrity\Shredding\PersonalData;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\ReadView;
use RuntimeException;
use Throwable;

/**
 * Writes everything an auditor needs to check the recorded history without
 * this package: the versions as GDPdU tables (index.xml + CSV) and as exact
 * JSON lines, the anchors with their proof files, a Merkle inclusion proof
 * for every exported version, a verification report, the format
 * specification and a checksum list.
 *
 * Everything is read in one consistent view, up to the global head at its
 * start. The export is written to a temporary directory next to the target,
 * readable for the owner only, and moved into place when complete. Data that
 * cannot be exported as it should (e.g. tampered rows) is exported as far as
 * possible and listed in the report; the export never stops at it.
 *
 * Memory: the Merkle tree of one anchor at a time (about 100 bytes per
 * version of its range).
 */
class AuditorExport
{
    /** @var list<string> */
    private array $problems = [];

    public function __construct(
        private readonly IntegrityChecker $checker,
        private readonly MerkleTree $tree,
        private readonly VersionHashes $hashes,
        private readonly AnchorManager $drivers,
        private readonly GdpduDtd $dtd,
        private readonly CanonicalSerializer $serializer,
        private readonly Filesystem $files,
    ) {}

    /**
     * @throws InvalidArgumentException when the target is a file or a directory that is not empty
     * @throws RuntimeException when the GDPdU DTD is not available
     */
    public function export(string $directory, ExportOptions $options): ExportResult
    {
        $directory = rtrim($directory, '/\\') === '' ? $directory : rtrim($directory, '/\\');

        if ($this->files->exists($directory) && ! $this->files->isDirectory($directory)) {
            throw new InvalidArgumentException("The export path [{$directory}] is a file.");
        }

        if ($this->files->isDirectory($directory) && ($this->files->files($directory) !== [] || $this->files->directories($directory) !== [])) {
            throw new InvalidArgumentException("The export directory [{$directory}] is not empty.");
        }

        $dtd = $options->withoutDtd ? null : $this->dtd->contents($options->fetchDtd);
        $this->files->ensureDirectoryExists(dirname($directory));
        $partial = dirname($directory).'/.'.basename($directory).'.partial-'.bin2hex(random_bytes(4));
        $this->files->makeDirectory($partial.'/proofs', 0700, true);
        $this->problems = [];

        try {
            $result = $this->write($partial, $options, $dtd);
            $this->restrict($partial);
        } catch (Throwable $e) {
            $this->files->deleteDirectory($partial);

            throw $e;
        }

        if ($this->files->isDirectory($directory)) {
            $this->files->deleteDirectory($directory);
        }

        if (! @rename($partial, $directory)) {
            $this->files->deleteDirectory($partial);

            throw new RuntimeException("Cannot move the export to [{$directory}].");
        }

        return $result;
    }

    private function write(string $directory, ExportOptions $options, ?string $dtd): ExportResult
    {
        $tables = Tables::all($options->reveal);

        [$checks, $head] = ReadView::run($this->connection(), function () use ($directory, $options, $tables): array {
            // First: the check lists external anchor statements before the read view is fixed.
            $checks = ['all' => $this->checker->checkAll(), 'files' => null];
            $stored = $this->connection()->table(Config::string('model-integrity.tables.heads'))->where('chain', ChainName::GLOBAL)->value('sequence');
            $head = is_numeric($stored) ? (int) $stored : 0;
            $anchors = array_values(AnchorRecord::query()->where('to_sequence', '<=', $head)->orderBy('to_sequence')->get()->all());

            $used = $this->writeVersions($directory, $tables['versions'], $tables['inclusion_proofs'], $options, $anchors, $head);
            $this->writeAnchors($directory, $tables['anchors'], $tables['anchor_proofs'], array_values(array_filter($anchors, fn (AnchorRecord $anchor): bool => isset($used[$anchor->id]))));
            $this->writeStoredFiles($directory, $tables['files']);

            return [$checks, $head];
        });

        // Hashing file contents takes long; it runs outside the read transaction.
        if ($options->files) {
            $checks['files'] = $this->checker->checkFiles();
        }

        $supplier = config('model-integrity.export.supplier');
        $index = new GdpduIndex(
            is_array($supplier) && is_string($supplier['name'] ?? null) ? $supplier['name'] : '',
            is_array($supplier) && is_string($supplier['location'] ?? null) ? $supplier['location'] : '',
            'Recorded versions, anchors and proofs (mueller-schmitz/laravel-model-integrity)',
        );
        $this->files->put($directory.'/index.xml', $index->build(array_values($tables)));

        if ($dtd !== null) {
            $this->files->put($directory.'/'.GdpduIndex::DTD, $dtd);
        }

        $result = new ExportResult($checks, $head, $this->problems);
        (new ExportReport)->write($directory, $result, $options);
        $this->files->copy(__DIR__.'/../../resources/spec.md', $directory.'/SPEC.md');
        $this->writeChecksums($directory);

        return $result;
    }

    /**
     * Streams the versions in sequence order and writes each one's inclusion
     * proof right away, building the tree of one anchor at a time.
     *
     * @param  list<AnchorRecord>  $anchors  sorted by to_sequence
     * @return array<int, true> ids of the anchors of exported versions
     */
    private function writeVersions(string $directory, Table $table, Table $proofTable, ExportOptions $options, array $anchors, int $head): array
    {
        $csv = CsvWriter::open($directory.'/'.$table->file, $table);
        $proofs = CsvWriter::open($directory.'/'.$proofTable->file, $proofTable);
        $jsonl = fopen($directory.'/versions.jsonl', 'wb');
        $personal = app(PersonalData::class);
        $used = [];
        $current = null;
        $levels = null;

        if ($jsonl === false) {
            throw new RuntimeException("Cannot write [{$directory}/versions.jsonl].");
        }

        $query = Version::query()
            ->where('sequence', '<=', $head)
            ->when($options->type !== null, fn ($query) => $query->where('versionable_type', $options->type))
            ->when($options->from !== null, fn ($query) => $query->where('created_at', '>=', $options->from?->utc()->format('Y-m-d H:i:s.u')))
            ->when($options->to !== null, fn ($query) => $query->where('created_at', '<=', $options->to?->utc()->format('Y-m-d H:i:s.u')));

        foreach ($query->lazyById(500, 'sequence') as $version) {
            $anchor = $this->anchorFor($anchors, $version->sequence);

            if ($anchor !== null && $anchor !== $current) {
                $current = $anchor;
                $levels = $this->levels($anchor);
            }

            if ($anchor !== null) {
                $used[$anchor->id] = true;
                $index = $version->sequence - $anchor->from_sequence;
                $proofs->write([$version->sequence, $anchor->id, $index, $anchor->to_sequence - $anchor->from_sequence + 1, $levels === null ? null : implode(' ', $levels->path($index))]);
            }

            try {
                $snapshot = $version->snapshot;
                $encoded = $this->serializer->encode($snapshot);
                $line = $this->serializer->encode(['sequence' => $version->sequence, 'hash' => $version->hash, 'envelope' => $version->toEnvelope()]);
            } catch (Throwable $e) {
                $this->problem("Version {$version->sequence} cannot be read as recorded ({$e->getMessage()}); exported as stored.");
                $snapshot = null;
                $raw = $version->getRawOriginal('snapshot');
                $encoded = is_string($raw) ? $raw : null;
                $line = json_encode(['sequence' => $version->sequence, 'hash' => $version->hash, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            }

            $row = [
                $version->sequence, $version->versionable_type, $version->versionable_id, $version->version, $version->event,
                $version->created_at, $version->created_at, $this->iso($version->created_at),
                $version->actor_type, $version->actor_id, $version->reason, $version->hash_format, $version->schema_version,
                $version->hash, $version->prev_hash, $version->global_prev_hash, $anchor?->id, $encoded,
            ];

            if ($options->reveal) {
                try {
                    $row[] = $snapshot === null ? null : $this->serializer->encode($personal->reveal($snapshot)->snapshot);
                } catch (Throwable) {
                    // Reported by the verification; the column stays empty.
                    $row[] = null;
                }
            }

            $csv->write($row);
            fwrite($jsonl, $line."\n");
        }

        $csv->close();
        $proofs->close();
        fclose($jsonl);

        return $used;
    }

    /**
     * The anchor's tree from the stored version hashes; null if they cannot
     * form it (missing or malformed versions), which verification reports.
     */
    private function levels(AnchorRecord $anchor): ?MerkleLevels
    {
        $leaves = array_values(iterator_to_array($this->hashes->between($anchor->from_sequence, $anchor->to_sequence)));

        if (count($leaves) !== $anchor->to_sequence - $anchor->from_sequence + 1) {
            $this->problem("Anchor #{$anchor->id}: versions of its range are missing; its inclusion proofs are left empty.");

            return null;
        }

        try {
            return $this->tree->levels($leaves);
        } catch (Throwable $e) {
            $this->problem("Anchor #{$anchor->id}: {$e->getMessage()} Its inclusion proofs are left empty.");

            return null;
        }
    }

    /**
     * @param  list<AnchorRecord>  $anchors
     */
    private function writeAnchors(string $directory, Table $anchorTable, Table $proofTable, array $anchors): void
    {
        $anchorCsv = CsvWriter::open($directory.'/'.$anchorTable->file, $anchorTable);
        $proofCsv = CsvWriter::open($directory.'/'.$proofTable->file, $proofTable);

        foreach ($anchors as $anchor) {
            try {
                $statement = $anchor->statement();
                $statementFile = 'proofs/'.$statement->fileName();
                $this->files->put($directory.'/'.$statementFile, $statement->canonical());
            } catch (Throwable $e) {
                $this->problem("Anchor #{$anchor->id} is no valid statement ({$e->getMessage()}); exported without statement file.");
                $statement = null;
                $statementFile = null;
            }

            $anchorCsv->write([
                $anchor->id, $anchor->anchor_format, $anchor->from_sequence, $anchor->to_sequence, $anchor->merkle_root, $anchor->prev_digest, $anchor->digest,
                $anchor->created_at, $anchor->created_at, $this->iso($anchor->created_at), $statementFile,
            ]);

            $proofs = AnchorProof::query()->where('anchor_id', $anchor->id)->orderBy('id')->get();
            $latest = $proofs->keyBy('driver')->all();

            foreach ($proofs as $proof) {
                $file = null;

                if ($statement !== null && isset($latest[$proof->driver]) && $latest[$proof->driver]->is($proof) && ($contents = $proof->contents()) !== null) {
                    $file = $this->proofFile($directory, $statement->fileName(), $proof->driver, $contents);
                }

                $proofCsv->write([$proof->id, $anchor->id, $proof->driver, $proof->created_at, $proof->created_at, $this->iso($proof->created_at), $file]);
            }
        }

        $anchorCsv->close();
        $proofCsv->close();
    }

    private function proofFile(string $directory, string $statementFile, string $driver, string $contents): ?string
    {
        try {
            $anchor = $this->drivers->driver($driver);
        } catch (Throwable) {
            return null;
        }

        if (! $anchor instanceof ExportsProofs) {
            return null;
        }

        $file = 'proofs/'.$statementFile.'.'.$anchor->proofFileExtension();
        $this->files->put($directory.'/'.$file, $contents);

        return $file;
    }

    protected function writeStoredFiles(string $directory, Table $table): void
    {
        $csv = CsvWriter::open($directory.'/'.$table->file, $table);

        foreach (StoredFile::query()->lazyById(500) as $file) {
            $stored = $file->getAttribute('created_at');
            $created = $stored instanceof DateTimeInterface || is_string($stored) ? CarbonImmutable::parse($stored)->utc() : null;
            $csv->write([$file->sha256, $file->disk, $file->path, $file->size, $file->mime, $file->key_id, $created, $created]);
        }

        $csv->close();
    }

    private function writeChecksums(string $directory): void
    {
        $lines = [];

        foreach ($this->files->allFiles($directory) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $lines[$relative] = hash_file('sha256', $file->getPathname()).'  '.$relative;
        }

        ksort($lines, SORT_STRING);
        $this->files->put($directory.'/SHA256SUMS', implode("\n", $lines)."\n");
    }

    /**
     * The export holds personal data in plain text: readable for its owner only.
     */
    private function restrict(string $directory): void
    {
        @chmod($directory, 0700);
        @chmod($directory.'/proofs', 0700);

        foreach ($this->files->allFiles($directory) as $file) {
            @chmod($file->getPathname(), 0600);
        }
    }

    /**
     * @param  list<AnchorRecord>  $anchors  sorted by to_sequence
     */
    private function anchorFor(array $anchors, int $sequence): ?AnchorRecord
    {
        $low = 0;
        $high = count($anchors) - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $anchor = $anchors[$middle];

            if ($sequence < $anchor->from_sequence) {
                $high = $middle - 1;
            } elseif ($sequence > $anchor->to_sequence) {
                $low = $middle + 1;
            } else {
                return $anchor;
            }
        }

        return null;
    }

    private function problem(string $message): void
    {
        $this->problems[] = $message;
    }

    private function iso(?CarbonImmutable $time): ?string
    {
        return $time?->format('Y-m-d\TH:i:s.u\Z');
    }

    private function connection(): Connection
    {
        $configured = config('model-integrity.connection');

        /** @var Connection */
        return DB::connection(is_string($configured) ? $configured : null);
    }
}
