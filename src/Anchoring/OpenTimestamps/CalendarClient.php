<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;
use RuntimeException;

/**
 * Talks to OpenTimestamps calendar servers: submits digests and asks for
 * the upgrade of pending attestations.
 */
class CalendarClient
{
    /** Calendars answer with a few hundred bytes; more is not a timestamp. */
    private const int MAX_RESPONSE_SIZE = 10_000;

    public function __construct(
        private readonly Factory $http,
        private readonly Codec $codec,
    ) {}

    /**
     * @return Timestamp the calendar's timestamp for the digest, usually ending in a pending attestation
     */
    public function submit(string $calendar, string $digest): Timestamp
    {
        $response = $this->request()
            ->withBody($digest, 'application/x-www-form-urlencoded')
            ->post($this->url($calendar, 'digest'));

        return $this->timestamp($calendar, $response, $digest);
    }

    /**
     * @param  string  $commitment  the message of a pending attestation of this calendar
     * @return Timestamp|null the timestamp from the commitment on, null while the calendar has none yet
     */
    public function upgrade(string $calendar, string $commitment): ?Timestamp
    {
        $response = $this->request()->get($this->url($calendar, 'timestamp/'.bin2hex($commitment)));

        return $response->status() === 404 ? null : $this->timestamp($calendar, $response, $commitment);
    }

    private function request(): PendingRequest
    {
        return $this->http
            ->withHeaders(['Accept' => 'application/vnd.opentimestamps.v1', 'User-Agent' => 'mueller-schmitz/laravel-model-integrity'])
            ->connectTimeout(Config::integer('model-integrity.anchors.opentimestamps.timeout', 10))
            ->timeout(Config::integer('model-integrity.anchors.opentimestamps.timeout', 10))
            ->withoutRedirecting();
    }

    private function timestamp(string $calendar, Response $response, string $message): Timestamp
    {
        if (! $response->successful()) {
            throw new RuntimeException("Calendar [{$calendar}] answered with HTTP {$response->status()}.");
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_RESPONSE_SIZE) {
            throw new RuntimeException("Calendar [{$calendar}] answered with more than ".self::MAX_RESPONSE_SIZE.' bytes.');
        }

        try {
            return $this->codec->decodeTimestamp($body, $message);
        } catch (InvalidTimestampException $e) {
            throw new RuntimeException("Calendar [{$calendar}] did not answer with a timestamp: {$e->getMessage()}", previous: $e);
        }
    }

    private function url(string $calendar, string $path): string
    {
        return rtrim($calendar, '/').'/'.$path;
    }
}
