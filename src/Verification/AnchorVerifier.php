<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Verification;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatus;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorVerification;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\ListsStatements;
use MuellerSchmitz\ModelIntegrity\Anchoring\MerkleTree;
use MuellerSchmitz\ModelIntegrity\Anchoring\VersionHashes;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Models\AnchorProof;
use MuellerSchmitz\ModelIntegrity\Models\AnchorRecord;
use MuellerSchmitz\ModelIntegrity\Recording\ChainName;
use Throwable;

/**
 * Checks the anchors against the versions they attest and against their
 * proofs outside the database.
 *
 * Anchors that a driver can list (e.g. files on the anchor disk) are checked
 * against the versions as well, whether or not the database still has them:
 * an attacker who rewrites the chain and deletes the anchor rows is detected,
 * while a statement left over by a failed anchor run that matches the
 * versions is not an error.
 *
 * @internal used by the IntegrityChecker inside its read transaction
 */
class AnchorVerifier
{
    /** Tolerated difference between the clocks of the servers that record versions and anchors. */
    private const int CLOCK_SKEW_MINUTES = 10;

    public function __construct(
        private readonly AnchorManager $drivers,
        private readonly MerkleTree $tree,
        private readonly VersionHashes $versions,
    ) {}

    /**
     * @param  list<array{string, AnchorStatement|null}>  $listed  statements listed by the drivers, see listedStatements()
     */
    public function inspect(array $listed): IntegrityResult
    {
        $errors = [];
        $count = 0;
        $maxSequence = $this->maxSequence();
        /** @var array<string, true> $known digests of the anchors in the database */
        $known = [];
        $previous = null;

        foreach (AnchorRecord::query()->with('proofs')->lazyById(200) as $anchor) {
            $count++;
            $known[$anchor->digest] = true;

            try {
                $statement = $anchor->statement();
            } catch (InvalidArgumentException $e) {
                $errors[] = $this->error(IntegrityErrorType::AnchorMismatch, "Anchor #{$anchor->id} is not a valid statement: {$e->getMessage()}", $anchor->to_sequence);

                continue;
            }

            if ($statement->digest() !== $anchor->digest) {
                $errors[] = $this->error(IntegrityErrorType::AnchorMismatch, "The digest of anchor #{$anchor->id} does not match its fields.", $anchor->to_sequence);
            }

            $expectedFrom = $previous === null ? 1 : $previous->to_sequence + 1;

            if ($anchor->from_sequence !== $expectedFrom || $anchor->prev_digest !== $previous?->digest) {
                $errors[] = $this->error(IntegrityErrorType::AnchorMismatch, "Anchor #{$anchor->id} does not continue the previous anchor (expected sequence {$expectedFrom} and its digest).", $anchor->to_sequence);
            }

            if (($problem = $this->rangeProblem($statement, $maxSequence)) !== null) {
                $errors[] = $this->error($problem[0], "Anchor #{$anchor->id} {$problem[1]}", $anchor->to_sequence);
            } elseif (($recorded = $this->versions->latestCreatedAt($anchor->from_sequence, $anchor->to_sequence)) !== null
                && $anchor->created_at !== null && $recorded->greaterThan($anchor->created_at->addMinutes(self::CLOCK_SKEW_MINUTES))) {
                // An anchor is created after its versions; otherwise its time was rewritten.
                $errors[] = $this->error(IntegrityErrorType::AnchorMismatch, "Anchor #{$anchor->id} was created at {$anchor->created_at->toIso8601String()}, but attests versions recorded after that ({$recorded->toIso8601String()}).", $anchor->to_sequence);
            }

            foreach ($this->proofProblems($anchor, $statement) as [$type, $message]) {
                $errors[] = $this->error($type, $message, $anchor->to_sequence);
            }

            $previous = $anchor;
        }

        $head = DB::connection($this->connection())->table(Config::string('model-integrity.tables.heads'))->where('chain', ChainName::ANCHORS)->first();

        if ($head === null || ! is_numeric($head->sequence) || (int) $head->sequence !== ($previous->to_sequence ?? 0) || $head->hash !== $previous?->digest) {
            $errors[] = $this->error(IntegrityErrorType::AnchorMismatch, 'The anchors head does not match the last anchor.', null);
        }

        foreach ($listed as [$location, $statement]) {
            if ($statement === null) {
                $errors[] = $this->error(IntegrityErrorType::AnchorMismatch, "The anchor statement at [{$location}] cannot be read.", null);
            } elseif (! isset($known[$statement->digest()]) && ($problem = $this->rangeProblem($statement, $maxSequence)) !== null) {
                $errors[] = $this->error($problem[0], "The anchor statement at [{$location}], which the database does not contain, {$problem[1]}", $statement->toSequence);
            }
        }

        return new IntegrityResult($errors, $count, null);
    }

    /**
     * Compares the statement with the versions it covers.
     *
     * @return array{IntegrityErrorType, string}|null
     */
    private function rangeProblem(AnchorStatement $statement, int $maxSequence): ?array
    {
        if ($statement->toSequence > $maxSequence) {
            return [IntegrityErrorType::TruncatedChain, "attests versions up to sequence {$statement->toSequence}, but the chain ends at {$maxSequence}."];
        }

        $count = 0;
        $root = $this->tree->root((function () use ($statement, &$count) {
            foreach ($this->versions->between($statement->fromSequence, $statement->toSequence) as $hash) {
                $count++;

                yield $hash;
            }
        })());

        if ($count !== $statement->toSequence - $statement->fromSequence + 1 || $root !== $statement->merkleRoot) {
            return [IntegrityErrorType::AnchorMismatch, "attests versions {$statement->fromSequence} to {$statement->toSequence} that differ from the stored ones."];
        }

        return null;
    }

    /**
     * The latest proof of each driver must attest the statement.
     *
     * @return list<array{IntegrityErrorType, string}>
     */
    private function proofProblems(AnchorRecord $anchor, AnchorStatement $statement): array
    {
        /** @var array<string, AnchorProof> $latest */
        $latest = $anchor->proofs->keyBy('driver')->all();

        if ($latest === []) {
            return [[IntegrityErrorType::AnchorMismatch, "Anchor #{$anchor->id} has no proof."]];
        }

        $problems = [];

        foreach ($latest as $driver => $proof) {
            try {
                $anchorDriver = $this->drivers->driver($driver);
            } catch (InvalidArgumentException|IntegrityConfigurationException) {
                $problems[] = [IntegrityErrorType::Unverifiable, "The [{$driver}] proof of anchor #{$anchor->id} was not checked: the driver is not available."];

                continue;
            }

            $contents = $proof->contents();

            if ($contents === null) {
                $problems[] = [IntegrityErrorType::AnchorMismatch, "The [{$driver}] proof of anchor #{$anchor->id} is not valid base64."];

                continue;
            }

            try {
                $verification = $anchorDriver->verify($statement, $contents);
            } catch (Throwable $e) {
                $problems[] = [IntegrityErrorType::Unverifiable, "The [{$driver}] proof of anchor #{$anchor->id} could not be checked: {$e->getMessage()}"];

                continue;
            }

            if ($verification->status === AnchorStatus::Invalid) {
                $problems[] = [IntegrityErrorType::AnchorMismatch, "The [{$driver}] proof of anchor #{$anchor->id} is invalid: {$verification->message}"];
            } elseif (($late = $this->lateProof($anchor, $verification)) !== null) {
                $problems[] = [IntegrityErrorType::AnchorMismatch, "The [{$driver}] proof of anchor #{$anchor->id} {$late}"];
            }
        }

        return $problems;
    }

    /**
     * A proof attests when the statement existed. It says nothing about the
     * time between recording a version and that attestation, so a proof
     * obtained long after the versions it attests may be a fresh proof for a
     * statement forged afterwards. The delay is measured from the versions'
     * own times, which are hashed and anchored; the anchor row's time is not
     * covered by any hash. Versions recorded before anchoring was enabled
     * (anchors.since, outside the database) count from that date.
     */
    private function lateProof(AnchorRecord $anchor, AnchorVerification $verification): ?string
    {
        $recorded = $this->versions->latestCreatedAt($anchor->from_sequence, $anchor->to_sequence);

        if ($recorded === null) {
            return null;
        }

        $since = $this->anchoringSince();
        $from = $since !== null && $since->greaterThan($recorded) ? $since : $recorded;
        $hours = Config::integer('model-integrity.anchors.max_delay_hours', 72);
        $deadline = $from->addHours($hours);
        $reference = $from === $since ? "anchoring was enabled ({$since->toIso8601String()})" : "the versions it attests were recorded (last at {$recorded->toIso8601String()})";

        if ($verification->status === AnchorStatus::Confirmed && $verification->attestedAt?->greaterThan($deadline)) {
            return "was attested at {$verification->attestedAt->toIso8601String()}, more than {$hours} hours after {$reference}.";
        }

        if ($verification->status === AnchorStatus::Pending && CarbonImmutable::now()->greaterThan($deadline)) {
            return "is still pending more than {$hours} hours after {$reference}; run model-integrity:anchor-upgrade.";
        }

        return null;
    }

    private function anchoringSince(): ?CarbonImmutable
    {
        $since = config('model-integrity.anchors.since');

        if ($since === null || $since === '') {
            return null;
        }

        if (! is_string($since)) {
            throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.since', 'a date or null');
        }

        try {
            return new CarbonImmutable($since, 'UTC');
        } catch (Throwable) {
            throw IntegrityConfigurationException::invalidConfig('model-integrity.anchors.since', 'a date or null');
        }
    }

    /**
     * The statements the enabled drivers hold. Call this before the read
     * transaction starts: a statement is only written after the versions it
     * covers were committed, so they are visible in the later read view. A
     * statement listed after the read view was fixed could cover versions the
     * view does not contain, and would be reported as a truncated chain.
     *
     * @return list<array{string, AnchorStatement|null}>
     */
    public function listedStatements(): array
    {
        $listed = [];

        foreach ($this->drivers->enabledDrivers() as $name) {
            $driver = $this->drivers->driver($name);

            if ($driver instanceof ListsStatements) {
                foreach ($driver->statements() as $entry) {
                    $listed[] = [$entry['location'], $entry['statement']];
                }
            }
        }

        return $listed;
    }

    private function maxSequence(): int
    {
        $max = DB::connection($this->connection())->table(Config::string('model-integrity.tables.versions'))->max('sequence');

        return is_numeric($max) ? (int) $max : 0;
    }

    private function error(IntegrityErrorType $type, string $message, ?int $sequence): IntegrityError
    {
        return new IntegrityError($type, $message, null, null, null, $sequence);
    }

    private function connection(): ?string
    {
        $connection = config('model-integrity.connection');

        return is_string($connection) ? $connection : null;
    }
}
