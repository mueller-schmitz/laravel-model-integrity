<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Rfc3161;

use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;
use RuntimeException;

/**
 * Verifies a time-stamp token: the CMS signature over the TSTInfo, and that
 * the signing certificate is trusted for time stamping at the time stamped.
 */
final class TokenVerifier
{
    public function __construct(
        private readonly string $caFile,
        private readonly ?string $intermediatesFile = null,
    ) {}

    /**
     * @param  string  $token  the DER-encoded ContentInfo
     * @return TstInfo the signed content, read from what the signature check returned
     *
     * @throws InvalidTimestampException when the token is not valid
     * @throws RuntimeException when it cannot be checked (ext-openssl or certificate files missing)
     */
    public function verify(string $token): TstInfo
    {
        if (! function_exists('openssl_cms_verify')) {
            throw new RuntimeException('Checking RFC 3161 time-stamps requires the PHP extension openssl.');
        }

        if (! is_readable($this->caFile)) {
            throw new RuntimeException("Cannot read the certificate file [{$this->caFile}].");
        }

        $this->assertTstInfoContent($token);

        $files = [];

        try {
            $input = $files[] = $this->temporaryFile($token);
            $signers = $files[] = $this->temporaryFile('');
            $content = $files[] = $this->temporaryFile('');
            $this->openSslErrors();

            // NOVERIFY skips OpenSSL's certificate check, which uses the current
            // time and the S/MIME purpose; CertificateChain checks it instead.
            $valid = openssl_cms_verify($input, OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY, $signers, [], null, $content, null, null, OPENSSL_ENCODING_DER);

            if ($valid !== true) {
                throw new InvalidTimestampException('The signature of the time-stamp token is not valid: '.(implode('; ', $this->openSslErrors()) ?: 'unknown error'));
            }

            $info = TstInfo::decode((string) file_get_contents($content));
            $signer = (string) file_get_contents($signers);
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        // RFC 3161: one signer, the TSA. The output holds one certificate per signer.
        if (preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $signer, $matches) !== 1) {
            throw new InvalidTimestampException('A time-stamp token must have exactly one signer with its certificate.');
        }

        (new CertificateChain($this->caFile, $this->intermediatesFile))->verify($matches[0][0], $info->genTime);

        return $info;
    }

    /**
     * The signature check does not look at the content type; a signed
     * document of the same TSA must not pass for a time-stamp.
     */
    private function assertTstInfoContent(string $token): void
    {
        $response = new TimeStampResponse(TimeStampResponse::GRANTED, null, $token, '');
        $response->info();
    }

    /**
     * Reads and clears OpenSSL's error queue.
     *
     * @return list<string>
     */
    private function openSslErrors(): array
    {
        $errors = [];

        while (($error = openssl_error_string()) !== false) {
            $errors[] = $error;
        }

        return $errors;
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mi-tsa-');

        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        return $path;
    }
}
