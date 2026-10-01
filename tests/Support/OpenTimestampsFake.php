<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Attestation;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Codec;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Op;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\Timestamp;

/**
 * OpenTimestamps calendars and an Esplora block source behind one HTTP fake.
 * Http::fake() stubs match in the order they were added, so responses that
 * tests add later live in a registry instead of further stubs.
 */
final class OpenTimestampsFake
{
    public const string ALICE = 'https://alice.example';

    public const string BOB = 'https://bob.example';

    public const string ESPLORA = 'https://esplora.example/api';

    /** @var list<string> calendars that answer with an error */
    public static array $failing = [];

    /** @var array<string, string> response bodies by URL */
    public static array $responses = [];

    public static bool $blockSourceDown = false;

    /**
     * Configures the driver for the fake and installs it.
     */
    public static function install(): void
    {
        self::$failing = [];
        self::$responses = [];
        self::$blockSourceDown = false;

        config(['model-integrity.anchors.opentimestamps' => [
            'calendars' => [self::ALICE, self::BOB],
            'min_calendars' => 1,
            'timeout' => 5,
            'esplora_url' => self::ESPLORA,
        ]]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            $calendar = 'https://'.parse_url($url, PHP_URL_HOST);

            if (str_starts_with($url, self::ESPLORA) && self::$blockSourceDown) {
                return Http::response('', 503);
            }

            if (in_array($calendar, self::$failing, true)) {
                return Http::response('', 503);
            }

            if (str_ends_with($url, '/digest')) {
                return Http::response((new Codec)->encodeTimestamp(self::calendarTimestamp($request->body(), $calendar)));
            }

            return isset(self::$responses[$url]) ? Http::response(self::$responses[$url]) : Http::response('', 404);
        });
    }

    /**
     * What a calendar answers for a digest: operations to its commitment,
     * attested as pending at the calendar.
     */
    public static function calendarTimestamp(string $digest, string $calendar): Timestamp
    {
        $timestamp = new Timestamp($digest);
        $timestamp->add(Op::append(hash('sha256', $calendar, true)))->add(Op::sha256())->attest(Attestation::pending($calendar));

        return $timestamp;
    }

    /**
     * Lets the calendar answer the upgrade of the digest's commitment with a
     * Bitcoin attestation, and the block source know the matching block.
     */
    public static function confirm(string $calendar, string $digest, int $height, string $time): void
    {
        $commitment = hash('sha256', $digest.hash('sha256', $calendar, true), true);
        $upgrade = new Timestamp($commitment);
        $leaf = $upgrade->add(Op::prepend("\x01"))->add(Op::sha256());
        $leaf->attest(Attestation::bitcoin($height));

        self::block($height, $leaf->message, $time);
        self::$responses[$calendar.'/timestamp/'.bin2hex($commitment)] = (new Codec)->encodeTimestamp($upgrade);
    }

    /**
     * A block whose header commits to the merkle root.
     */
    public static function block(int $height, string $merkleRoot, string $time): void
    {
        $header = pack('V', 0x20000000).str_repeat("\x00", 32).$merkleRoot.pack('V', (int) strtotime($time.' UTC')).pack('V', 0x17034219).pack('V', 1);
        $hash = bin2hex(strrev(hash('sha256', hash('sha256', $header, true), true)));

        self::$responses[self::ESPLORA."/block-height/{$height}"] = $hash;
        self::$responses[self::ESPLORA."/block/{$hash}/header"] = bin2hex($header);
    }
}
