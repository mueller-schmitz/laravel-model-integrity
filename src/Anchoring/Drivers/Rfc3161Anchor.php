<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Drivers;

use Closure;
use Illuminate\Http\Client\Factory;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorVerification;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\ExportsProofs;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\TimeStampRequest;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\TimeStampResponse;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\TokenVerifier;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\TstInfo;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;
use RuntimeException;

/**
 * Anchors statements with an RFC 3161 time-stamp authority, e.g. freetsa.org
 * or a qualified trust service provider. The proof is the TSA's complete
 * response; `openssl ts -verify` checks it independently.
 *
 * The token is trusted if its signature is valid and the TSA certificate
 * leads to the configured CA and was valid when it signed. Revocation is
 * not checked.
 */
class Rfc3161Anchor implements Anchor, ExportsProofs
{
    /** @var Closure(string): TimeStampRequest */
    private readonly Closure $requests;

    /**
     * @param  array<string, string>  $headers  e.g. credentials of a commercial TSA
     * @param  (Closure(string): TimeStampRequest)|null  $requests  creates the request for a digest
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $url,
        private readonly string $caFile,
        private readonly ?string $intermediatesFile,
        private readonly ?string $policy,
        private readonly int $timeout,
        private readonly array $headers = [],
        ?Closure $requests = null,
    ) {
        $this->requests = $requests ?? TimeStampRequest::for(...);
    }

    public function submit(AnchorStatement $statement): string
    {
        $request = ($this->requests)((string) hex2bin($statement->digest()));

        $response = $this->http
            ->withHeaders(['User-Agent' => 'mueller-schmitz/laravel-model-integrity', ...$this->headers])
            ->connectTimeout($this->timeout)
            ->timeout($this->timeout)
            ->withoutRedirecting()
            ->withBody($request->encode(), 'application/timestamp-query')
            ->post($this->url);

        if (! $response->successful()) {
            throw new RuntimeException("The time-stamp authority answered with HTTP {$response->status()}.");
        }

        try {
            $timeStamp = TimeStampResponse::decode($response->body());

            if (! $timeStamp->granted()) {
                throw new RuntimeException("The time-stamp authority refused the request (status {$timeStamp->status}".($timeStamp->statusText === null ? '' : ": {$timeStamp->statusText}").').');
            }

            $info = (new TokenVerifier($this->caFile, $this->intermediatesFile))->verify((string) $timeStamp->token);
        } catch (InvalidTimestampException $e) {
            throw new RuntimeException("The time-stamp authority's answer is not valid: {$e->getMessage()}", previous: $e);
        }

        if (($problem = $this->problem($info, $statement)) !== null) {
            throw new RuntimeException("The time-stamp authority's answer is not valid: {$problem}");
        }

        // A response to an earlier request (replayed or cached) carries another nonce.
        if ($info->nonce === null || $info->nonce !== ltrim($request->nonce, "\x00")) {
            throw new RuntimeException("The time-stamp authority's answer does not carry the nonce of the request.");
        }

        return $timeStamp->encoded;
    }

    public function verify(AnchorStatement $statement, string $proof): AnchorVerification
    {
        try {
            $timeStamp = TimeStampResponse::decode($proof);

            if (! $timeStamp->granted()) {
                return AnchorVerification::invalid('The proof is no granted time-stamp.');
            }

            // Throws a RuntimeException if the token cannot be checked here: unverifiable, not invalid.
            $info = (new TokenVerifier($this->caFile, $this->intermediatesFile))->verify((string) $timeStamp->token);
        } catch (InvalidTimestampException $e) {
            return AnchorVerification::invalid($e->getMessage());
        }

        if (($problem = $this->problem($info, $statement)) !== null) {
            return AnchorVerification::invalid($problem);
        }

        return AnchorVerification::confirmed("Time-stamped at {$info->genTime->toIso8601String()} (serial ".bin2hex($info->serialNumber).').', $info->genTime);
    }

    public function proofFileExtension(): string
    {
        return 'tsr';
    }

    private function problem(TstInfo $info, AnchorStatement $statement): ?string
    {
        if ($info->hashAlgorithm !== TimeStampRequest::SHA256 || bin2hex($info->hashedMessage) !== $statement->digest()) {
            return 'The time-stamp is for another digest than the statement.';
        }

        if ($this->policy !== null && $info->policy !== $this->policy) {
            return "The time-stamp was issued under policy {$info->policy}, not {$this->policy}.";
        }

        return null;
    }
}
