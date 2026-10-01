<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatus;

/*
 * Against freetsa.org. Not part of CI: it depends on a third-party server.
 * Run with MI_LIVE_TSA=1.
 */

beforeEach(function (): void {
    if (getenv('MI_LIVE_TSA') !== '1' || ! function_exists('openssl_cms_verify')) {
        $this->markTestSkipped('Set MI_LIVE_TSA=1 (and enable ext-openssl) to run against freetsa.org.');
    }

    config(['model-integrity.anchors.rfc3161' => [
        'url' => 'https://freetsa.org/tsr',
        'ca_file' => __DIR__.'/../../Fixtures/rfc3161/freetsa-ca.pem',
    ]]);
});

it('gets and verifies a time-stamp from freetsa.org', function (): void {
    $driver = app(AnchorManager::class)->driver('rfc3161');
    $statement = new AnchorStatement(1, 1, 1, hash('sha256', 'live test '.microtime()), null);

    expect($driver->verify($statement, $driver->submit($statement))->status)->toBe(AnchorStatus::Confirmed);
});
