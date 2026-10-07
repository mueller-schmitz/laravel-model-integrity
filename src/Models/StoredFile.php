<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;
use MuellerSchmitz\ModelIntegrity\Exceptions\MissingSubjectKeyException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ShreddedSubjectException;
use MuellerSchmitz\ModelIntegrity\Files\FileCipher;
use MuellerSchmitz\ModelIntegrity\Shredding\SubjectKeys;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * A stored file, content-addressed by its SHA-256 hash.
 *
 * Records are immutable and recorded as versions in the global chain, so a
 * changed or removed record is detected like any other manipulation.
 *
 * A file stored for a data subject is encrypted with the subject's key:
 * hash and size refer to the encrypted file as it lies on the disk.
 *
 * @property int $id
 * @property string $sha256
 * @property string $disk
 * @property string $path
 * @property int $size
 * @property string|null $mime
 * @property string|null $key_id
 */
class StoredFile extends Model
{
    use HasIntegrity;

    public const UPDATED_AT = null;

    protected string $integrityMode = 'immutable';

    protected string $integrityDeletes = 'forbid';

    /**
     * The upload time is interpreted in the app timezone when read; the
     * version records its own UTC time, so it is left out of the snapshot.
     *
     * @var list<string>
     */
    protected array $integrityExcept = ['created_at'];

    /**
     * Files stored without encryption keep the snapshot they had before the
     * column existed; the key of an encrypted file is part of its snapshot.
     *
     * @var list<string>
     */
    protected array $integrityOmitNull = ['key_id'];

    protected $guarded = [];

    /** Fixed alias: versions store it forever, and apps may enforce a morph map. */
    public const MORPH_ALIAS = 'model-integrity.file';

    public function getMorphClass(): string
    {
        return self::MORPH_ALIAS;
    }

    public function getTable(): string
    {
        return Config::string('model-integrity.tables.files', 'integrity_files');
    }

    public function getConnectionName(): ?string
    {
        $connection = config('model-integrity.connection');

        return is_string($connection) ? $connection : parent::getConnectionName();
    }

    /**
     * Whether the file is encrypted with the key of a data subject.
     */
    public function isEncrypted(): bool
    {
        return $this->keyId() !== null;
    }

    /**
     * Whether the key of the file's data subject was shredded: the content
     * cannot be read any more.
     */
    public function isShredded(): bool
    {
        return ($keyId = $this->keyId()) !== null && app(SubjectKeys::class)->state($keyId) === 'shredded';
    }

    /**
     * The content of the file, decrypted if it is encrypted.
     *
     * @return resource
     *
     * @throws ShreddedSubjectException when the key of the file's data subject was shredded
     * @throws MissingSubjectKeyException when the key is gone without having been shredded
     * @throws DecryptException when an encrypted file was changed, or its key cannot be unwrapped with the application key
     */
    public function readStream(): mixed
    {
        // Checked first: a shredded file is not fetched from the disk at all.
        $key = ($keyId = $this->keyId()) === null ? null : $this->subjectKey($keyId);
        $stream = Storage::disk($this->disk)->readStream($this->path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Stored file [{$this->sha256}] is missing on disk [{$this->disk}].");
        }

        return $key === null ? $stream : $this->decrypted($stream, $key);
    }

    public function contents(): string
    {
        if ($this->keyId() !== null) {
            $stream = $this->readStream();

            try {
                return (string) stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
        }

        $contents = Storage::disk($this->disk)->get($this->path);

        if ($contents === null) {
            throw new RuntimeException("Stored file [{$this->sha256}] is missing on disk [{$this->disk}].");
        }

        return $contents;
    }

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /**
     * Decrypts into a temporary stream (in memory, on the local disk beyond
     * PHP's limit for php://temp), which is removed when it is closed.
     *
     * @param  resource  $stream
     * @return resource
     */
    private function decrypted($stream, #[SensitiveParameter] string $key): mixed
    {
        $plain = fopen('php://temp', 'w+b');

        try {
            if ($plain === false) {
                throw new RuntimeException('Cannot create a temporary stream.');
            }

            app(FileCipher::class)->decrypt($stream, $plain, $key);
            rewind($plain);

            return $plain;
        } catch (Throwable $e) {
            if (is_resource($plain)) {
                fclose($plain);
            }

            throw $e instanceof DecryptException
                ? new DecryptException("Stored file [{$this->sha256}] cannot be decrypted: {$e->getMessage()}", previous: $e)
                : $e;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Read from the raw attributes: a record loaded before the column was
     * migrated has no key, also for models that prevent missing attributes.
     */
    private function keyId(): ?string
    {
        $keyId = $this->getAttributes()['key_id'] ?? null;

        return is_string($keyId) ? $keyId : null;
    }

    private function subjectKey(string $keyId): string
    {
        $keys = app(SubjectKeys::class);

        return $keys->find($keyId) ?? throw ($keys->state($keyId) === 'shredded'
            ? ShreddedSubjectException::file($this->sha256)
            : MissingSubjectKeyException::for($keyId));
    }
}
