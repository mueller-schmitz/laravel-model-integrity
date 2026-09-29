<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;
use RuntimeException;

/**
 * A stored file, content-addressed by its SHA-256 hash.
 *
 * Records are immutable and recorded as versions in the global chain, so a
 * changed or removed record is detected like any other manipulation.
 *
 * @property int $id
 * @property string $sha256
 * @property string $disk
 * @property string $path
 * @property int $size
 * @property string|null $mime
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
     * @return resource
     */
    public function readStream(): mixed
    {
        $stream = Storage::disk($this->disk)->readStream($this->path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Stored file [{$this->sha256}] is missing on disk [{$this->disk}].");
        }

        return $stream;
    }

    public function contents(): string
    {
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
}
