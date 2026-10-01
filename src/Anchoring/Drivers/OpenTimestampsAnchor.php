<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Drivers;

use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorVerification;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\ExportsProofs;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\UpgradesProofs;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Attestation;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\CalendarClient;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Codec;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\DetachedTimestamp;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\EsploraClient;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Timestamp;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;
use RuntimeException;
use Throwable;

/**
 * Anchors statements in Bitcoin through OpenTimestamps calendars, free and
 * without an account. The proof is a standard .ots file for the statement's
 * digest; it is pending until a calendar has committed to a Bitcoin block,
 * which takes a few hours, and is completed by model-integrity:anchor-upgrade.
 *
 * Bitcoin attestations are checked against the block header from the block
 * source: without that check, anyone who can write the proof could invent
 * an attestation.
 */
class OpenTimestampsAnchor implements Anchor, ExportsProofs, UpgradesProofs
{
    /**
     * @param  list<string>  $calendars  submitted to, and the only ones asked for upgrades
     */
    public function __construct(
        private readonly CalendarClient $client,
        private readonly Codec $codec,
        private readonly EsploraClient $blocks,
        private readonly array $calendars,
        private readonly int $minCalendars,
    ) {}

    public function submit(AnchorStatement $statement): string
    {
        $digest = (string) hex2bin($statement->digest());
        $timestamp = new Timestamp($digest);
        $answered = 0;
        $failures = [];

        foreach ($this->calendars as $calendar) {
            try {
                $timestamp->merge($this->client->submit($calendar, $digest));
                $answered++;
            } catch (Throwable $e) {
                $failures[] = "[{$calendar}] {$e->getMessage()}";
            }
        }

        if ($answered < max(1, $this->minCalendars)) {
            throw new RuntimeException("Only {$answered} of the required {$this->minCalendars} calendars answered: ".implode('; ', $failures));
        }

        return $this->codec->encodeDetached(new DetachedTimestamp($digest, $timestamp));
    }

    public function verify(AnchorStatement $statement, string $proof): AnchorVerification
    {
        try {
            $file = $this->codec->decodeDetached($proof);
        } catch (InvalidTimestampException $e) {
            return AnchorVerification::invalid("The proof is no valid OpenTimestamps file: {$e->getMessage()}");
        }

        if (bin2hex($file->digest) !== $statement->digest()) {
            return AnchorVerification::invalid('The proof is for another digest than the statement.');
        }

        $confirmed = null;
        $pending = false;

        foreach ($file->timestamp->allAttestations() as [$attestation, $message]) {
            if ($attestation->isPending()) {
                $pending = true;
            }

            if (! $attestation->isBitcoin()) {
                continue;
            }

            // Throws when the block source cannot answer: the proof is then unverifiable, not invalid.
            $block = $this->blocks->block((int) $attestation->height());

            if ($block->merkleRoot !== $message) {
                return AnchorVerification::invalid("The Bitcoin attestation for block {$block->height} does not match that block.");
            }

            if ($confirmed === null || $block->time < $confirmed->time) {
                $confirmed = $block;
            }
        }

        if ($confirmed !== null) {
            return AnchorVerification::confirmed("Attested in Bitcoin block {$confirmed->height}.", $confirmed->time);
        }

        return $pending
            ? AnchorVerification::pending('Waiting for a Bitcoin attestation; run model-integrity:anchor-upgrade.')
            : AnchorVerification::invalid('The proof has neither a Bitcoin nor a pending attestation.');
    }

    public function upgrade(AnchorStatement $statement, string $proof): ?string
    {
        $file = $this->codec->decodeDetached($proof);
        $attestations = $file->timestamp->allAttestations();

        // Complete: one Bitcoin attestation proves the time; further ones add nothing.
        foreach ($attestations as [$attestation]) {
            if ($attestation->isBitcoin()) {
                return null;
            }
        }

        $upgraded = $this->codec->decodeDetached($proof)->timestamp;

        foreach ($attestations as [$attestation, $commitment]) {
            $calendar = $this->configuredCalendar($attestation);

            if ($calendar === null || ($upgrade = $this->client->upgrade($calendar, $commitment)) === null) {
                continue;
            }

            foreach ($upgraded->nodesFor($commitment) as $node) {
                $node->merge($upgrade);
            }
        }

        // Only additions: the previous proof stays part of the upgraded one.
        if (! $upgraded->contains($file->timestamp) || $file->timestamp->contains($upgraded)) {
            return null;
        }

        return $this->codec->encodeDetached(new DetachedTimestamp($file->digest, $upgraded));
    }

    public function proofFileExtension(): string
    {
        return 'ots';
    }

    /**
     * The configured calendar a pending attestation names. Other URIs are
     * never requested: the proof comes from the database and might point the
     * server to internal addresses.
     */
    private function configuredCalendar(Attestation $attestation): ?string
    {
        if (! $attestation->isPending()) {
            return null;
        }

        $uri = rtrim((string) $attestation->uri(), '/');

        foreach ($this->calendars as $calendar) {
            if (rtrim($calendar, '/') === $uri) {
                return $calendar;
            }
        }

        return null;
    }
}
