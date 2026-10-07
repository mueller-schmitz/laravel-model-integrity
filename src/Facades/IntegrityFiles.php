<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Facades;

use Illuminate\Support\Facades\Facade;
use MuellerSchmitz\ModelIntegrity\Files\FileStore;

/**
 * @method static \MuellerSchmitz\ModelIntegrity\Models\StoredFile store(\SplFileInfo|string|resource $file, ?string $mime = null, \Illuminate\Database\Eloquent\Model|string|null $subject = null)
 * @method static \MuellerSchmitz\ModelIntegrity\Models\StoredFile|null find(string $sha256)
 *
 * @see FileStore
 */
class IntegrityFiles extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FileStore::class;
    }
}
