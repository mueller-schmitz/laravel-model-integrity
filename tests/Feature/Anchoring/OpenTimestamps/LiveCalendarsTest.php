<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatus;
use MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps\EsploraClient;

/*
 * Against the real public calendars and blockstream.info. Not part of CI:
 * it depends on third-party servers. Run with MI_LIVE_OTS=1.
 */

beforeEach(function (): void {
    if (getenv('MI_LIVE_OTS') !== '1') {
        $this->markTestSkipped('Set MI_LIVE_OTS=1 to run against the public OpenTimestamps calendars.');
    }
});

it('gets pending proofs from the default calendars', function (): void {
    $driver = app(AnchorManager::class)->driver('opentimestamps');
    $statement = new AnchorStatement(1, 1, 1, hash('sha256', 'live test '.microtime()), null);

    expect($driver->verify($statement, $driver->submit($statement))->status)->toBe(AnchorStatus::Pending);
});

it('reads a real block header from the default block source', function (): void {
    $block = app(EsploraClient::class)->block(358391);

    expect(bin2hex($block->merkleRoot))->toBe('007ee445d23ad061af4a36b809501fab1ac4f2d7e7a739817dd0cbb7ec661b8a');
});
