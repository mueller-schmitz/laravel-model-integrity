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
 * Revocation (CRL, OCSP) is not checked.
 */
final class CertificateChain
{
    private const int MAX_LENGTH = 5;

    public function __construct(
        private readonly string $caFile,
        private readonly ?string $intermediatesFile = null,
    ) {}

    /**
     * @throws InvalidTimestampException when the certificate is not trusted for time stamping at that time
     * @throws RuntimeException when the configured certificate files cannot be read
     */
    public function verify(string $signerPem, CarbonImmutable $at): void
    {
        $roots = $this->certificates($this->caFile, required: true);
        $intermediates = $this->intermediatesFile === null ? [] : $this->certificates($this->intermediatesFile, required: true);
        $current = $this->read($signerPem);

        $this->assertTimeStamping($current);

        for ($length = 0; $length < self::MAX_LENGTH; $length++) {
            $this->assertValidAt($current, $at);

            foreach ($roots as $root) {
                if (openssl_x509_verify($current, $root) === 1) {
                    $this->assertValidAt($root, $at);

                    return;
                }
            }

            $issuer = null;

            foreach ($intermediates as $intermediate) {
                if (openssl_x509_verify($current, $intermediate) === 1 && $this->isCa($intermediate)) {
                    $issuer = $intermediate;

                    break;
                }
            }

            if ($issuer === null) {
                throw new InvalidTimestampException('The TSA certificate does not lead to a trusted root certificate.');
            }

            $current = $issuer;
        }

        throw new InvalidTimestampException('The TSA certificate chain is too long.');
    }

    private function assertTimeStamping(OpenSSLCertificate $certificate): void
    {
        $usage = $this->extension($certificate, 'extendedKeyUsage');

        if (! in_array('Time Stamping', array_map(trim(...), explode(',', $usage)), true)) {
            throw new InvalidTimestampException('The TSA certificate is not meant for time stamping.');
        }
    }

    private function assertValidAt(OpenSSLCertificate $certificate, CarbonImmutable $at): void
    {
        $parsed = openssl_x509_parse($certificate);
        $from = is_array($parsed) ? ($parsed['validFrom_time_t'] ?? null) : null;
        $to = is_array($parsed) ? ($parsed['validTo_time_t'] ?? null) : null;

        if (! is_int($from) || ! is_int($to) || $at->getTimestamp() < $from || $at->getTimestamp() > $to) {
            $name = is_array($parsed) && is_string($parsed['name'] ?? null) ? $parsed['name'] : 'unknown';

            throw new InvalidTimestampException("Certificate [{$name}] was not valid at {$at->toIso8601String()}.");
        }
    }

    private function isCa(OpenSSLCertificate $certificate): bool
    {
        return str_contains($this->extension($certificate, 'basicConstraints'), 'CA:TRUE');
    }

    /**
     * An extension as OpenSSL prints it, or an empty string.
     */
    private function extension(OpenSSLCertificate $certificate, string $name): string
    {
        $parsed = openssl_x509_parse($certificate);
        $extensions = is_array($parsed) ? ($parsed['extensions'] ?? null) : null;
        $value = is_array($extensions) ? ($extensions[$name] ?? null) : null;

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<OpenSSLCertificate>
     */
    private function certificates(string $file, bool $required): array
    {
        $contents = is_readable($file) ? file_get_contents($file) : false;

        if ($contents === false) {
            throw new RuntimeException("Cannot read the certificate file [{$file}].");
        }

        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $contents, $matches);
        $certificates = array_map($this->read(...), $matches[0]);

        if ($required && $certificates === []) {
            throw new RuntimeException("The certificate file [{$file}] contains no certificate.");
        }

        return $certificates;
    }

    private function read(string $pem): OpenSSLCertificate
    {
        $certificate = openssl_x509_read($pem);

        if ($certificate === false) {
            throw new InvalidTimestampException('Cannot read the TSA certificate.');
        }

        return $certificate;
    }
}
