<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Models\StoredFile;

/**
 * A column holding the SHA-256 hash of a stored file. The hash becomes part
 * of the snapshot, so the file is part of the model's history.
 *
 * Setting the attribute does not store anything: store the file first with
 * IntegrityFiles::store() and assign the result. Reading the attribute loads
 * the StoredFile with one query per model; for lists read the hash with
 * getRawOriginal() and load files with IntegrityFiles::find().
 *
 * @implements CastsAttributes<StoredFile|null, mixed>
 */
class AsIntegrityFile implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?StoredFile
    {
        return is_string($value) ? StoredFile::query()->where('sha256', $value)->first() : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof StoredFile => $value->sha256,
            is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1 => $value,
            default => throw new InvalidArgumentException(
                "[{$key}] expects a StoredFile or a lowercase SHA-256 hash; store files with IntegrityFiles::store() first."
            ),
        };
    }

    /**
     * Arrays and JSON contain the hash only, not the storage path.
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof StoredFile ? $value->sha256 : null;
    }
}
