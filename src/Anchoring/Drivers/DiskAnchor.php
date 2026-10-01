<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\Drivers;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorStatement;
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorVerification;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor;
use MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\ListsStatements;
use RuntimeException;

/**
 * Writes each statement as a file to a disk the database cannot change, e.g.
 * storage on another server or a bucket with object lock. The file contains
 * the canonical statement, so its SHA-256 hash is the anchored digest.
 *
 * The proof is the path of the file.
 */
class DiskAnchor implements Anchor, ListsStatements
{
    private const string FILE_PATTERN = '/^(\d{20})-([0-9a-f]{64})\.json$/';

    public function __construct(
        private readonly string $disk,
        private readonly string $path,
    ) {}

    public function submit(AnchorStatement $statement): string
    {
        $path = $this->pathFor($statement);
        $disk = $this->filesystem();

        if ($disk->exists($path)) {
            if ($disk->get($path) === $statement->canonical()) {
                return $path;
            }

            throw new RuntimeException("Anchor file [{$path}] on disk [{$this->disk}] exists with other content; it is not overwritten.");
        }

        if (! $disk->put($path, $statement->canonical())) {
            throw new RuntimeException("Cannot write anchor file [{$path}] to disk [{$this->disk}].");
        }

        return $path;
    }

    public function verify(AnchorStatement $statement, string $proof): AnchorVerification
    {
        $disk = $this->filesystem();

        // The name is derived from the statement; a file with another name and
        // the same content (e.g. uploaded through the application) is no proof.
        // The directory may differ: it is the path configured at the time.
        if (basename($proof) !== $statement->fileName()) {
            return AnchorVerification::invalid("Anchor file [{$proof}] is not the file of this statement [{$statement->fileName()}].");
        }

        if (! $disk->exists($proof)) {
            return AnchorVerification::invalid("Anchor file [{$proof}] is missing on disk [{$this->disk}].");
        }

        if ($disk->get($proof) !== $statement->canonical()) {
            return AnchorVerification::invalid("Anchor file [{$proof}] on disk [{$this->disk}] does not match the statement recomputed from the database.");
        }

        return AnchorVerification::confirmed("Anchor file [{$proof}] on disk [{$this->disk}] matches.");
    }

    public function statements(): iterable
    {
        $files = array_filter(
            $this->filesystem()->files($this->prefix()),
            fn (string $file): bool => preg_match(self::FILE_PATTERN, basename($file)) === 1,
        );
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            try {
                $statement = AnchorStatement::fromCanonical((string) $this->filesystem()->get($file));
            } catch (InvalidArgumentException) {
                $statement = null;
            }

            yield ['location' => "{$this->disk}:{$file}", 'statement' => $statement];
        }
    }

    private function pathFor(AnchorStatement $statement): string
    {
        return ($this->prefix() === '' ? '' : $this->prefix().'/').$statement->fileName();
    }

    private function prefix(): string
    {
        return trim($this->path, '/');
    }

    private function filesystem(): Filesystem
    {
        return Storage::disk($this->disk);
    }
}
