<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\CertificateChain;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\TimeStampResponse;
use MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161\TokenVerifier;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

beforeEach(function (): void {
    if (! function_exists('openssl_cms_verify')) {
        $this->markTestSkipped('ext-openssl is not available.');
    }

    $this->fixtures = __DIR__.'/../../../Fixtures/rfc3161';
});

function tokenOf(string $file): string
{
    return (string) TimeStampResponse::decode((string) file_get_contents($file))->token;
}

it('verifies a real token of freetsa.org against its CA', function (): void {
    $info = (new TokenVerifier($this->fixtures.'/freetsa-ca.pem'))->verify(tokenOf($this->fixtures.'/freetsa.tsr'));

    expect($info->genTime->format('Y-m-d H:i:s'))->toBe('2026-10-01 11:58:29');
});

it('verifies a token of the test TSA against the test CA', function (): void {
    expect((new TokenVerifier($this->fixtures.'/test-ca.pem'))->verify(tokenOf($this->fixtures.'/test.tsr'))->serialNumber)->toBe("\x02");
});

it('rejects tokens of a TSA the configured CA did not certify', function (string $token, string $ca): void {
    (new TokenVerifier($this->fixtures.'/'.$ca))->verify(tokenOf($this->fixtures.'/'.$token));
})->with([
    'foreign TSA' => ['foreign.tsr', 'test-ca.pem'],
    'freetsa against the test CA' => ['freetsa.tsr', 'test-ca.pem'],
    'test TSA against freetsa' => ['test.tsr', 'freetsa-ca.pem'],
])->throws(InvalidTimestampException::class, 'certificate');

it('rejects a token whose signed content was changed', function (): void {
    $token = tokenOf($this->fixtures.'/test.tsr');
    $position = strpos($token, '20261001');
    $token[$position + 9] = $token[$position + 9] === '1' ? '2' : '1';

    (new TokenVerifier($this->fixtures.'/test-ca.pem'))->verify($token);
})->throws(InvalidTimestampException::class, 'signature');

it('fails without a readable CA file', function (): void {
    (new TokenVerifier($this->fixtures.'/missing.pem'))->verify(tokenOf($this->fixtures.'/test.tsr'));
})->throws(RuntimeException::class, 'missing.pem');

it('requires the time-stamping purpose', function (): void {
    (new CertificateChain($this->fixtures.'/test-ca.pem'))->verify((string) file_get_contents($this->fixtures.'/test-noeku.pem'), CarbonImmutable::now());
})->throws(InvalidTimestampException::class, 'time stamping');

it('checks validity at the time of the time-stamp, not now', function (string $at, bool $valid): void {
    $verify = fn () => (new CertificateChain($this->fixtures.'/test-ca.pem'))->verify((string) file_get_contents($this->fixtures.'/test-tsa.pem'), CarbonImmutable::parse($at));

    $valid ? expect($verify())->toBeNull() : expect($verify)->toThrow(InvalidTimestampException::class, 'valid');
})->with([
    'within validity' => ['2030-01-01', true],
    'before validity' => ['2000-01-01', false],
    'after validity' => ['2200-01-01', false],
]);

it('requires a critical extended key usage with time stamping as the only purpose', function (string $certificate): void {
    (new CertificateChain($this->fixtures.'/test-ca.pem'))->verify((string) file_get_contents($this->fixtures.'/'.$certificate), CarbonImmutable::now());
})->with([
    'not critical' => ['test-noncritical.pem'],
    'several purposes' => ['test-multi.pem'],
])->throws(InvalidTimestampException::class, 'time stamping');

it('follows a chain through an intermediate CA', function (): void {
    $verifier = new TokenVerifier($this->fixtures.'/test-ca.pem', $this->fixtures.'/test-sub.pem');

    expect($verifier->verify(tokenOf($this->fixtures.'/sub.tsr'))->serialNumber)->not->toBe('')
        ->and(fn () => (new TokenVerifier($this->fixtures.'/test-ca.pem'))->verify(tokenOf($this->fixtures.'/sub.tsr')))
        ->toThrow(InvalidTimestampException::class, 'certificate');
});

it('does not accept an intermediate certificate that is no CA', function (): void {
    (new TokenVerifier($this->fixtures.'/test-ca.pem', $this->fixtures.'/test-nonca.pem'))->verify(tokenOf($this->fixtures.'/nonca.tsr'));
})->throws(InvalidTimestampException::class, 'certificate');

it('does not accept a self-signed TSA with the name of the trusted CA', function (): void {
    (new TokenVerifier($this->fixtures.'/test-ca.pem'))->verify(tokenOf($this->fixtures.'/self.tsr'));
})->throws(InvalidTimestampException::class, 'certificate');

it('does not accept signed content that is no TSTInfo', function (): void {
    (new TokenVerifier($this->fixtures.'/test-ca.pem'))->verify((string) file_get_contents($this->fixtures.'/data-token.der'));
})->throws(InvalidTimestampException::class, 'TSTInfo');

it('requires exactly one signer', function (): void {
    (new TokenVerifier($this->fixtures.'/test-ca.pem'))->verify((string) file_get_contents($this->fixtures.'/two-signers.der'));
})->throws(InvalidTimestampException::class, 'one signer');

it('tries every root with the right key, e.g. after a root was renewed', function (): void {
    // The first root has the same key but was not valid yet at the time of the time-stamp.
    $roots = tempnam(sys_get_temp_dir(), 'mi-roots');
    file_put_contents($roots, file_get_contents($this->fixtures.'/test-ca-later.pem').file_get_contents($this->fixtures.'/test-ca.pem'));

    try {
        expect((new TokenVerifier($roots))->verify(tokenOf($this->fixtures.'/test.tsr'))->serialNumber)->toBe("\x02");
    } finally {
        unlink($roots);
    }
});

it('reports an unusable CA file as a configuration problem, not as an invalid token', function (): void {
    $broken = tempnam(sys_get_temp_dir(), 'mi-ca');
    file_put_contents($broken, "-----BEGIN CERTIFICATE-----\nbroken\n-----END CERTIFICATE-----\n");

    try {
        (new TokenVerifier($broken))->verify(tokenOf($this->fixtures.'/test.tsr'));
    } finally {
        unlink($broken);
    }
})->throws(RuntimeException::class);
