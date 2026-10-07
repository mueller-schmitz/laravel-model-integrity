<?php

declare(strict_types=1);

use Illuminate\Contracts\Encryption\DecryptException;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Files\FileCipher;
use Symfony\Component\Process\Process;

/*
 * Reference vectors pin file format 1. They were written with PyNaCl
 * (libsodium) and an HKDF from the Python standard library, independently
 * of this package.
 *
 * A failing reference test means stored files would no longer decrypt.
 * Never update the fixtures to make it pass; introduce a new format instead.
 */

const FILE_FORMAT_FIXTURES = __DIR__.'/../../Fixtures/file-format-1';

dataset('file format 1 vectors', function (): array {
    $fixture = json_decode((string) file_get_contents(FILE_FORMAT_FIXTURES.'/vectors.json'), true, flags: JSON_THROW_ON_ERROR);

    return collect($fixture['vectors'])
        ->mapWithKeys(fn (array $vector): array => [$vector['description'] => [$vector, base64_decode($fixture['key'], true)]])
        ->all();
});

/**
 * @return resource
 */
function cipherStream(string $contents = '')
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

function cipherEncrypt(string $plain, string $key): string
{
    $target = cipherStream();
    (new FileCipher)->encrypt(cipherStream($plain), $target, $key);
    rewind($target);

    return (string) stream_get_contents($target);
}

function cipherDecrypt(string $encrypted, string $key): string
{
    $target = cipherStream();
    (new FileCipher)->decrypt(cipherStream($encrypted), $target, $key);
    rewind($target);

    return (string) stream_get_contents($target);
}

beforeEach(function (): void {
    $this->key = str_repeat('s', 32);
});

it('decrypts the reference vectors', function (array $vector, string $key): void {
    $plain = cipherDecrypt((string) file_get_contents(FILE_FORMAT_FIXTURES.'/'.$vector['file']), $key);

    expect(strlen($plain))->toBe($vector['plaintext_size'])
        ->and(hash('sha256', $plain))->toBe($vector['plaintext_sha256']);
})->with('file format 1 vectors');

it('round-trips contents of any length', function (int $length): void {
    $plain = $length === 0 ? '' : substr(str_repeat('integrity ', intdiv($length, 10) + 1), 0, $length);
    $encrypted = cipherEncrypt($plain, $this->key);
    $chunks = max(1, (int) ceil($length / 65536));

    expect(cipherDecrypt($encrypted, $this->key))->toBe($plain)
        ->and(substr($encrypted, 0, 5))->toBe("MIFE\x01")
        ->and(strlen($encrypted))->toBe(45 + $length + 17 * $chunks);
})->with([0, 1, 65535, 65536, 65537, 131072, 200000]);

it('encrypts the same content differently each time', function (): void {
    expect(cipherEncrypt('same content', $this->key))->not->toBe(cipherEncrypt('same content', $this->key));
});

it('does not contain the plain text', function (): void {
    expect(cipherEncrypt('Ada Lovelace, 10 St James Square', $this->key))->not->toContain('Lovelace');
});

it('rejects another key', function (): void {
    cipherDecrypt(cipherEncrypt('secret', $this->key), str_repeat('x', 32));
})->throws(DecryptException::class, 'cannot be decrypted with its key');

it('rejects changed content', function (): void {
    $encrypted = cipherEncrypt('secret content', $this->key);
    $encrypted[50] = $encrypted[50] === 'a' ? 'b' : 'a';

    cipherDecrypt($encrypted, $this->key);
})->throws(DecryptException::class, 'cannot be decrypted with its key');

it('rejects a changed salt', function (): void {
    $encrypted = cipherEncrypt('secret content', $this->key);
    $encrypted[5] = $encrypted[5] === 'a' ? 'b' : 'a';

    cipherDecrypt($encrypted, $this->key);
})->throws(DecryptException::class, 'cannot be decrypted with its key');

it('rejects a file cut off after a full chunk', function (): void {
    $encrypted = cipherEncrypt(str_repeat('a', 65536 + 10), $this->key);

    cipherDecrypt(substr($encrypted, 0, 45 + 65536 + 17), $this->key);
})->throws(DecryptException::class, 'ends before its final chunk');

it('rejects a file cut off within a chunk', function (): void {
    $encrypted = cipherEncrypt(str_repeat('a', 1000), $this->key);

    cipherDecrypt(substr($encrypted, 0, -1), $this->key);
})->throws(DecryptException::class, 'cannot be decrypted with its key');

it('rejects data appended to the final chunk', function (): void {
    cipherDecrypt(cipherEncrypt('secret', $this->key).'more', $this->key);
})->throws(DecryptException::class, 'cannot be decrypted with its key');

it('rejects data after a full final chunk', function (): void {
    cipherDecrypt(cipherEncrypt(str_repeat('a', 65536), $this->key).'more', $this->key);
})->throws(DecryptException::class, 'data after its final chunk');

it('rejects swapped chunks', function (): void {
    $encrypted = cipherEncrypt(str_repeat('a', 65536).str_repeat('b', 65536).'end', $this->key);
    $first = substr($encrypted, 45, 65553);
    $second = substr($encrypted, 45 + 65553, 65553);

    cipherDecrypt(substr($encrypted, 0, 45).$second.$first.substr($encrypted, 45 + 2 * 65553), $this->key);
})->throws(DecryptException::class, 'cannot be decrypted with its key');

it('rejects content that is no encrypted file', function (string $contents): void {
    cipherDecrypt($contents, $this->key);
})->with([
    'plain text' => ['%PDF-1.4 just a document that is long enough to hold a header'],
    'too short' => ['MIFE'],
    'empty' => [''],
])->throws(DecryptException::class, 'not an encrypted file');

it('rejects an unknown format', function (): void {
    $encrypted = cipherEncrypt('secret', $this->key);
    $encrypted[4] = "\x02";

    cipherDecrypt($encrypted, $this->key);
})->throws(DecryptException::class, 'format 2 is not supported');

it('rejects a changed stream header', function (): void {
    $encrypted = cipherEncrypt('secret content', $this->key);
    $encrypted[30] = $encrypted[30] === 'a' ? 'b' : 'a';

    cipherDecrypt($encrypted, $this->key);
})->throws(DecryptException::class, 'cannot be decrypted with its key');

/**
 * Builds a file from the given chunks with libsodium directly, to produce
 * files this package never writes.
 *
 * @param  list<array{string, int}>  $chunks  plain text and tag
 */
function craftedFile(array $chunks, string $key): string
{
    $salt = random_bytes(16);
    [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push(hash_hkdf('sha256', $key, 32, 'model-integrity/file/1', $salt));
    $file = "MIFE\x01".$salt.$header;

    foreach ($chunks as [$plain, $tag]) {
        $file .= sodium_crypto_secretstream_xchacha20poly1305_push($state, $plain, '', $tag);
    }

    return $file;
}

it('reads a file built from the specification', function (): void {
    $file = craftedFile([
        [str_repeat('a', 65536), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE],
        ['end', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL],
    ], $this->key);

    expect(cipherDecrypt($file, $this->key))->toBe(str_repeat('a', 65536).'end');
});

it('rejects files that are not chunked as the format requires', function (array $chunks): void {
    cipherDecrypt(craftedFile($chunks, $this->key), $this->key);
})->with([
    'short chunk without the final tag' => [[
        ['short', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE],
    ]],
    'empty final chunk after content' => [[
        [str_repeat('a', 65536), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE],
        ['', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL],
    ]],
    'other tag' => [[
        [str_repeat('a', 65536), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_REKEY],
        ['end', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL],
    ]],
])->throws(DecryptException::class, 'not chunked as file format 1 requires');

it('rejects a short chunk before the final one', function (): void {
    // Chunks are read in full blocks, so the two chunks are taken for one.
    cipherDecrypt(craftedFile([
        ['short', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE],
        ['end', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL],
    ], $this->key), $this->key);
})->throws(DecryptException::class, 'cannot be decrypted with its key');

it('reads chunks completely from a stream that returns less than asked for', function (): void {
    $plain = str_repeat('0123456789', 15000);
    $encrypted = cipherEncrypt($plain, $this->key);

    stream_wrapper_register('mi-short', ShortReadStream::class);
    ShortReadStream::$contents = $encrypted;

    try {
        $target = cipherStream();
        (new FileCipher)->decrypt(fopen('mi-short://file', 'rb'), $target, $this->key);
        rewind($target);

        expect(stream_get_contents($target))->toBe($plain);
    } finally {
        stream_wrapper_unregister('mi-short');
    }
});

/**
 * Returns at most 1000 bytes per read, like a network stream.
 */
class ShortReadStream
{
    public static string $contents = '';

    /** @var resource|null */
    public $context;

    private int $position = 0;

    public function stream_open(): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $part = substr(self::$contents, $this->position, min($count, 1000));
        $this->position += strlen($part);

        return $part;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$contents);
    }
}

it('tells whether a file opens with a key', function (string $contents): void {
    $encrypted = cipherEncrypt($contents, $this->key);

    expect((new FileCipher)->opensWith(cipherStream($encrypted), $this->key))->toBeTrue()
        ->and((new FileCipher)->opensWith(cipherStream($encrypted), str_repeat('x', 32)))->toBeFalse()
        ->and((new FileCipher)->opensWith(cipherStream('no encrypted file at all, but long enough for a header'), $this->key))->toBeFalse();
})->with(['empty' => [''], 'short' => ['secret'], 'several chunks' => [str_repeat('a', 140000)]]);

it('rejects keys that are not 32 bytes long', function (): void {
    cipherEncrypt('secret', 'short');
})->throws(InvalidArgumentException::class, '32 bytes');

it('names the missing sodium extension in a PHP without it', function (string $method): void {
    // -n starts PHP without its configuration, so without shared extensions.
    $script = <<<'PHP'
        require $argv[1].'/src/Exceptions/IntegrityConfigurationException.php';
        require $argv[1].'/src/Files/FileCipher.php';

        if (extension_loaded('sodium')) {
            exit('sodium is loaded');
        }

        $source = fopen('php://memory', 'r+');
        fwrite($source, str_repeat('x', 100));
        rewind($source);

        try {
            (new MuellerSchmitz\ModelIntegrity\Files\FileCipher)->{$argv[2]}($source, fopen('php://memory', 'r+'), str_repeat('s', 32));
            echo 'no exception';
        } catch (Throwable $e) {
            echo get_class($e).': '.$e->getMessage();
        }
        PHP;

    $process = new Process([PHP_BINARY, '-n', '-r', $script, '--', dirname(__DIR__, 3), $method]);
    $process->mustRun();

    if ($process->getOutput() === 'sodium is loaded') {
        $this->markTestSkipped('sodium is compiled into this PHP binary.');
    }

    expect($process->getOutput())->toBe(IntegrityConfigurationException::class.': The PHP extension [sodium] is needed for encrypted files.');
})->with(['encrypt', 'decrypt']);
