<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Files;

use Illuminate\Contracts\Encryption\DecryptException;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use RuntimeException;
use SensitiveParameter;
use SodiumException;

/**
 * Encrypts file contents with the key of a data subject, so that shredding
 * the key makes the file unreadable. Hashes are taken over the encrypted
 * file and stay valid.
 *
 * File format 1 (frozen; a change needs a new format):
 *
 *     "MIFE" | 0x01 | salt (16 bytes) | secretstream header (24 bytes) | chunks
 *
 * The file key is HKDF-SHA256(subject key, salt, info "model-integrity/file/1"),
 * which separates it from the use of the subject key for attributes. The
 * content is encrypted with libsodium's secretstream (XChaCha20-Poly1305) in
 * chunks of 64 KiB plain text; the last chunk carries the final tag, so a
 * shortened, extended or reordered file does not decrypt.
 */
class FileCipher
{
    public const string MAGIC = 'MIFE';

    public const int FORMAT = 1;

    private const int CHUNK = 65536;

    private const int SALT_BYTES = 16;

    private const int KEY_BYTES = 32;

    private const string INFO = 'model-integrity/file/1';

    /**
     * @param  resource  $source  plain content, read from its current position
     * @param  resource  $target
     * @param  string  $key  the raw key of the data subject (32 bytes)
     */
    public function encrypt($source, $target, #[SensitiveParameter] string $key): void
    {
        $this->ensureSupported();

        $salt = random_bytes(self::SALT_BYTES);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->fileKey($key, $salt));

        if (! is_string($state) || ! is_string($header)) {
            throw new RuntimeException('Cannot start the encryption of the file.');
        }

        $this->write($target, self::MAGIC.chr(self::FORMAT).$salt.$header);

        $chunk = $this->read($source, self::CHUNK);

        do {
            $next = $this->read($source, self::CHUNK);
            $final = $next === '';

            $this->write($target, sodium_crypto_secretstream_xchacha20poly1305_push(
                $state,
                $chunk,
                '',
                $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
            ));

            $chunk = $next;
        } while (! $final);
    }

    /**
     * Plain content is written to the target chunk by chunk: when this
     * throws, the target holds a part of the file and must be discarded.
     *
     * @param  resource  $source  encrypted content, read from its current position
     * @param  resource  $target
     * @param  string  $key  the raw key of the data subject (32 bytes)
     *
     * @throws DecryptException when the content is no encrypted file, was changed or belongs to another key
     */
    public function decrypt($source, $target, #[SensitiveParameter] string $key): void
    {
        $state = $this->open($source, $key);
        $first = true;

        while (($chunk = $this->read($source, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES)) !== '') {
            [$plain, $final] = $this->pull($state, $chunk, $first);
            $this->write($target, $plain);

            if ($final) {
                if ($this->read($source, 1) !== '') {
                    throw new DecryptException('The file holds data after its final chunk.');
                }

                return;
            }

            $first = false;
        }

        throw new DecryptException('The file ends before its final chunk.');
    }

    /**
     * Whether the file starts with a chunk that decrypts with the key. Reads
     * the first chunk only: it tells a wrong key, not a file changed later on.
     *
     * @param  resource  $source  encrypted content, read from its current position
     * @param  string  $key  the raw key of the data subject (32 bytes)
     */
    public function opensWith($source, #[SensitiveParameter] string $key): bool
    {
        try {
            $state = $this->open($source, $key);
            $this->pull($state, $this->read($source, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES), true);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    protected function supported(): bool
    {
        return extension_loaded('sodium');
    }

    /**
     * Checked before any sodium function or constant is used: without the
     * extension they fail with an Error that names no cause.
     *
     * @throws IntegrityConfigurationException
     */
    public function ensureSupported(): void
    {
        if (! $this->supported()) {
            throw IntegrityConfigurationException::missingExtension('sodium', 'encrypted files');
        }
    }

    /**
     * Reads the file header and returns the secretstream state for its chunks.
     *
     * @param  resource  $source
     */
    private function open($source, #[SensitiveParameter] string $key): string
    {
        $this->ensureSupported();

        $prefix = strlen(self::MAGIC) + 1;
        $length = $prefix + self::SALT_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $head = $this->read($source, $length);

        if (strlen($head) !== $length || ! str_starts_with($head, self::MAGIC)) {
            throw new DecryptException('The content is not an encrypted file.');
        }

        if (ord($head[$prefix - 1]) !== self::FORMAT) {
            throw new DecryptException('File format '.ord($head[$prefix - 1]).' is not supported.');
        }

        return sodium_crypto_secretstream_xchacha20poly1305_init_pull(
            substr($head, $prefix + self::SALT_BYTES),
            $this->fileKey($key, substr($head, $prefix, self::SALT_BYTES)),
        );
    }

    /**
     * Decrypts one chunk and enforces the chunking of format 1: only the
     * last chunk may be shorter, and it is empty only in an empty file.
     *
     * @return array{string, bool} the plain chunk and whether it is the final one
     */
    private function pull(string &$state, string $chunk, bool $first): array
    {
        try {
            $pulled = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
        } catch (SodiumException) {
            $pulled = false;
        }

        $plain = is_array($pulled) ? ($pulled[0] ?? null) : null;

        if (! is_array($pulled) || ! is_string($plain)) {
            throw new DecryptException('The file cannot be decrypted with its key: it was changed or encrypted with another key.');
        }

        $tag = $pulled[1] ?? null;
        $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

        $valid = $final
            ? ($plain !== '' || $first)
            : ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE && strlen($plain) === self::CHUNK);

        if (! $valid) {
            throw new DecryptException('The file is not chunked as file format '.self::FORMAT.' requires.');
        }

        return [$plain, $final];
    }

    private function fileKey(#[SensitiveParameter] string $key, string $salt): string
    {
        if (strlen($key) !== self::KEY_BYTES) {
            throw new InvalidArgumentException('A file is encrypted with a key of '.self::KEY_BYTES.' bytes.');
        }

        return hash_hkdf('sha256', $key, self::KEY_BYTES, self::INFO, $salt);
    }

    /**
     * Reads exactly $length bytes, fewer only at the end of the stream: a
     * short read of a remote stream must not end a chunk early.
     *
     * @param  resource  $stream
     */
    private function read($stream, int $length): string
    {
        $data = '';

        while (($missing = $length - strlen($data)) > 0 && ! feof($stream)) {
            $part = fread($stream, $missing);

            if ($part === false || ($part === '' && ! feof($stream))) {
                throw new RuntimeException('Cannot read from the stream.');
            }

            $data .= $part;
        }

        return $data;
    }

    /**
     * @param  resource  $stream
     */
    private function write($stream, string $data): void
    {
        if ($data !== '' && fwrite($stream, $data) !== strlen($data)) {
            throw new RuntimeException('Cannot write to the stream.');
        }
    }
}
