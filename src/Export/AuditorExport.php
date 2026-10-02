<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\ExportsProofs;
use MuellerSchmitz\ModelIntegrity\Anchoring\MerkleTree;
use MuellerSchmitz\ModelIntegrity\Anchoring\VersionHashes;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Models\AnchorProof;
use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;
use MuellerSchmitz\ModelIntegrity\Models\Version;
use MuellerSchmitz\ModelIntegrity\Shredding\PersonalData;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityChecker;
use MuellerSchmitz\ModelIntegrity\Verification\IntegrityResult;
use RuntimeException;
use Throwable;

/**
 * Writes everything an auditor needs to check the recorded history without
 * this package: the versions as GDPdU tables (index.xml + CSV) and as exact
 * JSON lines, the anchors with their proof files, a Merkle inclusion proof
 * for every exported version, a verification report, the format
 * specification and a checksum list.
 *
 * Inclusion proofs hold the hashes of one anchor's range in memory at a time.
 */
class AuditorExport
{
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
     * @return array{all: IntegrityResult, files: IntegrityResult|null}
     *
     * @throws InvalidArgumentException when the directory is not empty
     * @throws RuntimeException when the GDPdU DTD is not available
     */
    public function export(string $directory, ExportOptions $options): array
    {
        $directory = rtrim($directory, '/\\');

        if ($this->files->isDirectory($directory) && ($this->files->files($directory) !== [] || $this->files->directories($directory) !== [])) {
            throw new InvalidArgumentException("The export directory [{$directory}] is not empty.");
        }

        $dtd = $options->withoutDtd ? null : $this->dtd->contents($options->fetchDtd);

        $this->files->ensureDirectoryExists($directory.'/proofs');

        $checks = ['all' => $this->checker->checkAll(), 'files' => $options->files ? $this->checker->checkFiles() : null];
        $anchors = array_values(AnchorRecord::query()->with('proofs')->orderBy('to_sequence')->get()->all());
        $tables = Tables::all($options->reveal);

        $exported = $this->writeVersions($directory, $tables['versions'], $options, $anchors);
        $this->writeInclusionProofs($directory, $tables['inclusion_proofs'], $exported, $anchors);
        $this->writeAnchors($directory, $tables['anchors'], $tables['anchor_proofs'], array_values(array_filter($anchors, fn (AnchorRecord $anchor): bool => isset($exported[$anchor->id]))));
        $this->writeStoredFiles($directory, $tables['files']);

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

        (new ExportReport)->write($directory, $checks, $options);
        $this->files->copy(__DIR__.'/../../resources/spec.md', $directory.'/SPEC.md');
        $this->writeChecksums($directory);

        return $checks;
    }

    /**
     * @param  list<AnchorRecord>  $anchors  sorted by to_sequence
     * @return array<int, list<int>> exported sequences by anchor id
     */
    private function writeVersions(string $directory, Table $table, ExportOptions $options, array $anchors): array
    {
        $csv = CsvWriter::open($directory.'/'.$table->file, $table);
        $jsonl = fopen($directory.'/versions.jsonl', 'wb');
        $personal = app(PersonalData::class);
        $exported = [];

        if ($jsonl === false) {
            throw new RuntimeException("Cannot write [{$directory}/versions.jsonl].");
        }

        $query = Version::query()
            ->when($options->type !== null, fn ($query) => $query->where('versionable_type', $options->type))
            ->when($options->from !== null, fn ($query) => $query->where('created_at', '>=', $options->from?->format('Y-m-d H:i:s.u')))
            ->when($options->to !== null, fn ($query) => $query->where('created_at', '<=', $options->to?->format('Y-m-d H:i:s.u')));

        foreach ($query->lazyById(500) as $version) {
            $anchor = $this->anchorFor($anchors, $version->sequence);

            if ($anchor !== null) {
                $exported[$anchor->id][] = $version->sequence;
            }

            $row = [
                $version->sequence,
                $version->versionable_type,
                $version->versionable_id,
                $version->version,
                $version->event,
                $version->created_at,
                $version->created_at,
                $this->iso($version->created_at),
                $version->actor_type,
                $version->actor_id,
                $version->reason,
                $version->hash_format,
                $version->schema_version,
                $version->hash,
                $version->prev_hash,
                $version->global_prev_hash,
                $anchor?->id,
                $this->serializer->encode($version->snapshot),
            ];

            if ($options->reveal) {
                try {
                    $row[] = $this->serializer->encode($personal->reveal($version->snapshot)->snapshot);
                } catch (Throwable) {
                    // Reported by the verification; the column stays empty.
                    $row[] = null;
                }
            }

            $csv->write($row);
            fwrite($jsonl, $this->serializer->encode(['sequence' => $version->sequence, 'hash' => $version->hash, 'envelope' => $version->toEnvelope()])."\n");
        }

        $csv->close();
        fclose($jsonl);

        return $exported;
    }

    /**
     * @param  array<int, list<int>>  $exported
     * @param  list<AnchorRecord>  $anchors
     */
    private function writeInclusionProofs(string $directory, Table $table, array $exported, array $anchors): void
    {
        $csv = CsvWriter::open($directory.'/'.$table->file, $table);

        foreach ($anchors as $anchor) {
            if (! isset($exported[$anchor->id])) {
                continue;
            }

            $leaves = array_values(iterator_to_array($this->hashes->between($anchor->from_sequence, $anchor->to_sequence)));
            $size = $anchor->to_sequence - $anchor->from_sequence + 1;

            foreach ($exported[$anchor->id] as $sequence) {
                $index = $sequence - $anchor->from_sequence;

                // A range with missing versions has no valid proofs; verification reports it.
                $path = count($leaves) === $size ? implode(' ', $this->tree->auditPath($leaves, $index)) : null;

                $csv->write([$sequence, $anchor->id, $index, $size, $path]);
            }
        }

        $csv->close();
    }

    /**
     * @param  list<AnchorRecord>  $anchors
     */
    private function writeAnchors(string $directory, Table $anchorTable, Table $proofTable, array $anchors): void
    {
        $anchorCsv = CsvWriter::open($directory.'/'.$anchorTable->file, $anchorTable);
        $proofCsv = CsvWriter::open($directory.'/'.$proofTable->file, $proofTable);

        foreach ($anchors as $anchor) {
            $statement = $anchor->statement();
            $this->files->put($directory.'/proofs/'.$statement->fileName(), $statement->canonical());

            $anchorCsv->write([
                $anchor->id, $anchor->anchor_format, $anchor->from_sequence, $anchor->to_sequence, $anchor->merkle_root, $anchor->prev_digest, $anchor->digest,
                $anchor->created_at, $anchor->created_at, $this->iso($anchor->created_at), 'proofs/'.$statement->fileName(),
            ]);

            /** @var array<string, AnchorProof> $latest */
            $latest = $anchor->proofs->keyBy('driver')->all();

            foreach ($anchor->proofs as $proof) {
                $file = null;

                if ($latest[$proof->driver]->is($proof) && ($contents = $proof->contents()) !== null) {
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

    private function writeStoredFiles(string $directory, Table $table): void
    {
        $csv = CsvWriter::open($directory.'/'.$table->file, $table);

        foreach (StoredFile::query()->lazyById(500) as $file) {
            $stored = $file->getAttribute('created_at');
            $created = $stored instanceof DateTimeInterface || is_string($stored) ? CarbonImmutable::parse($stored)->utc() : null;
            $csv->write([$file->sha256, $file->disk, $file->path, $file->size, $file->mime, $created, $created]);
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

    private function iso(?CarbonImmutable $time): ?string
    {
        return $time?->format('Y-m-d\TH:i:s.u\Z');
    }
}
