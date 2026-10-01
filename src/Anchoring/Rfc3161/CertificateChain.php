<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161;

use Carbon\CarbonImmutable;
use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Checks that a TSA certificate leads to a trusted root and was valid at the
 * time of the time-stamp. OpenSSL's own chain check uses the current time
 * and the S/MIME purpose; a token must stay verifiable after the TSA
 * certificate expired, and TSA certificates are for time stamping only.
 *
 * Every possible path is tried, so renewed or cross-signed certificates with
 * the same key do not depend on their order in the files. Revocation (CRL,
 * OCSP) is not checked.
 */
final class CertificateChain
{
    private const int MAX_LENGTH = 5;

    private const string EXTENDED_KEY_USAGE = '2.5.29.37';

    private const string TIME_STAMPING = '1.3.6.1.5.5.7.3.8';

    public function __construct(
        private readonly string $caFile,
        private readonly ?string $intermediatesFile = null,
    ) {}

    /**
     * @throws InvalidTimestampException when the certificate is not trusted for time stamping at that time
     * @throws RuntimeException when the configured certificate files cannot be used
     */
    public function verify(string $signerPem, CarbonImmutable $at): void
    {
        $roots = $this->certificates($this->caFile);
        $intermediates = $this->intermediatesFile === null ? [] : $this->certificates($this->intermediatesFile);
        $signer = openssl_x509_read($signerPem);

        if ($signer === false) {
            throw new InvalidTimestampException('Cannot read the TSA certificate.');
        }

        $this->assertTimeStamping($signer);

        if (! $this->validAt($signer, $at)) {
            throw new InvalidTimestampException("The TSA certificate [{$this->name($signer)}] was not valid at {$at->toIso8601String()}.");
        }

        $candidates = array_filter($intermediates, fn (OpenSSLCertificate $certificate): bool => $this->isCa($certificate) && $this->validAt($certificate, $at));
        $validRoots = array_filter($roots, fn (OpenSSLCertificate $root): bool => $this->validAt($root, $at));

        if (! $this->leadsToRoot($signer, array_values($validRoots), array_values($candidates), 0)) {
            throw new InvalidTimestampException("The TSA certificate does not lead to a trusted root certificate that was valid at {$at->toIso8601String()}.");
        }
    }

    /**
     * @param  list<OpenSSLCertificate>  $roots
     * @param  list<OpenSSLCertificate>  $intermediates
     */
    private function leadsToRoot(OpenSSLCertificate $certificate, array $roots, array $intermediates, int $depth): bool
    {
        foreach ($roots as $root) {
            if (openssl_x509_verify($certificate, $root) === 1) {
                return true;
            }
        }

        if ($depth >= self::MAX_LENGTH) {
            return false;
        }

        foreach ($intermediates as $intermediate) {
            if (openssl_x509_verify($certificate, $intermediate) === 1 && $this->leadsToRoot($intermediate, $roots, $intermediates, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 3161, section 2.3: the extended key usage is critical and contains
     * time stamping as its only purpose. OpenSSL's parser does not tell
     * whether an extension is critical, so the certificate is read here.
     */
    private function assertTimeStamping(OpenSSLCertificate $certificate): void
    {
        foreach ($this->extensions($certificate) as [$oid, $critical, $value]) {
            if ($oid !== self::EXTENDED_KEY_USAGE) {
                continue;
            }

            $purposes = array_map(fn (DerNode $purpose): string => $purpose->oid(), Der::decode($value)->expect(Der::SEQUENCE)->children());

            if ($critical && $purposes === [self::TIME_STAMPING]) {
                return;
            }

            break;
        }

        throw new InvalidTimestampException('The TSA certificate is not meant for time stamping only (critical extended key usage "time stamping" required).');
    }

    /**
     * @return list<array{string, bool, string}> OID, critical, value of each extension
     */
    private function extensions(OpenSSLCertificate $certificate): array
    {
        if (! openssl_x509_export($certificate, $pem) || ! is_string($pem)) {
            throw new InvalidTimestampException('Cannot read the TSA certificate.');
        }

        $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s/', '', $pem) ?? '', true);

        if ($der === false) {
            throw new InvalidTimestampException('Cannot read the TSA certificate.');
        }

        $tbs = Der::decode($der)->expect(Der::SEQUENCE)->children()[0]->expect(Der::SEQUENCE)->children();
        $extensions = [];

        foreach ($tbs as $field) {
            // [3] EXPLICIT Extensions
            if ($field->tag !== 0xA3) {
                continue;
            }

            foreach ($field->children()[0]->expect(Der::SEQUENCE)->children() as $extension) {
                $parts = $extension->expect(Der::SEQUENCE)->children();
                $critical = count($parts) === 3 && $parts[1]->boolean();
                $extensions[] = [$parts[0]->oid(), $critical, $parts[count($parts) - 1]->expect(Der::OCTET_STRING)->content];
            }
        }

        return $extensions;
    }

    private function validAt(OpenSSLCertificate $certificate, CarbonImmutable $at): bool
    {
        $parsed = openssl_x509_parse($certificate);
        $from = is_array($parsed) ? ($parsed['validFrom_time_t'] ?? null) : null;
        $to = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

        return is_int($from) && is_int($to) && $at->getTimestamp() >= $from && $at->getTimestamp() <= $to;
    }

    private function isCa(OpenSSLCertificate $certificate): bool
    {
        $parsed = openssl_x509_parse($certificate);
        $extensions = is_array($parsed) ? ($parsed['extensions'] ?? null) : null;
        $constraints = is_array($extensions) ? ($extensions['basicConstraints'] ?? null) : null;

        return is_string($constraints) && str_contains($constraints, 'CA:TRUE');
    }

    private function name(OpenSSLCertificate $certificate): string
    {
        $parsed = openssl_x509_parse($certificate);

        return is_array($parsed) && is_string($parsed['name'] ?? null) ? $parsed['name'] : 'unknown';
    }

    /**
     * The certificates of a configured file. Problems are configuration
     * errors: the proof is unverifiable here, not invalid.
     *
     * @return list<OpenSSLCertificate>
     */
    private function certificates(string $file): array
    {
        $contents = is_readable($file) ? file_get_contents($file) : false;

        if ($contents === false) {
            throw new RuntimeException("Cannot read the certificate file [{$file}].");
        }

        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $contents, $matches);
        $certificates = [];

        foreach ($matches[0] as $pem) {
            // OpenSSL warns about unreadable certificates; the exception below reports it.
            set_error_handler(fn (): bool => true);

            try {
                $certificate = openssl_x509_read($pem);
            } finally {
                restore_error_handler();
            }

            if ($certificate === false) {
                throw new RuntimeException("The certificate file [{$file}] contains a certificate that cannot be read.");
            }

            $certificates[] = $certificate;
        }

        if ($certificates === []) {
            throw new RuntimeException("The certificate file [{$file}] contains no certificate.");
        }

        return $certificates;
    }
}
