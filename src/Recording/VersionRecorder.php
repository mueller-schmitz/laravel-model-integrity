<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Recording;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use MuellerSchmitz\ModelIntegrity\Events\VersionRecorded;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Exceptions\UnsupportedHashFormatException;
use MuellerSchmitz\ModelIntegrity\Hashing\CanonicalSerializer;
use MuellerSchmitz\ModelIntegrity\Hashing\Hasher;
use MuellerSchmitz\ModelIntegrity\Models\Version;

/**
 * Appends a version to the per-model chain and the global chain.
 *
 * The global head row is locked for the duration of the transaction. This
 * serializes all recording writes, which keeps the global sequence gapless.
 * The model's head row is locked afterwards; it provides the next version
 * number and prev_hash. Locking reads always see the latest committed state,
 * so the versions table itself needs no lock (and no UPDATE privilege).
 */
class VersionRecorder
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly Hasher $hasher,
        private readonly CanonicalSerializer $serializer,
        private readonly ActorResolver $actors,
    ) {}

    /**
     * Pass the snapshot as a closure whenever the stored row still exists: it
     * is then built after the head lock is acquired. Under REPEATABLE READ
     * (MySQL, MariaDB) the first plain read of a transaction fixes what it
     * sees; reading before waiting for the lock would miss changes that other
     * writers committed in the meantime, e.g. relation changes.
     *
     * @param  array<string, mixed>|Closure(): array<string, mixed>  $snapshot  canonical snapshot from the SnapshotBuilder
     * @param  array<string, mixed>|null  $context
     */
    public function record(
        Model $model,
        string $event,
        array|Closure $snapshot,
        int $schemaVersion = 1,
        ?string $reason = null,
        ?array $context = null,
    ): Version {
        $connection = $this->connectionFor($model);
        $format = $this->hashFormat();

        return $connection->transaction(function () use ($connection, $format, $model, $event, $snapshot, $schemaVersion, $reason, $context): Version {
            $head = $this->lockHead($connection, ChainName::GLOBAL);

            if ($head === null) {
                throw IntegrityConfigurationException::headMissing(ChainName::GLOBAL);
            }

            $type = $model->getMorphClass();
            $id = $this->modelKey($model);
            $chain = ChainName::for($type, $id);

            $modelHead = $this->lockHead($connection, $chain);

            if ($modelHead === null) {
                $connection->table($this->table('heads'))->insert(['chain' => $chain, 'sequence' => 0, 'hash' => null]);
                $modelHead = (object) ['sequence' => 0, 'hash' => null];
            }

            $snapshot = $snapshot instanceof Closure ? $snapshot() : $snapshot;

            $actor = $this->actors->resolve();
            $createdAt = CarbonImmutable::now('UTC');

            $envelope = [
                'format' => $format,
                'sequence' => $this->intValue($head->sequence) + 1,
                'versionable_type' => $type,
                'versionable_id' => $id,
                'version' => $this->intValue($modelHead->sequence) + 1,
                'event' => $event,
                'schema_version' => $schemaVersion,
                'snapshot' => $snapshot,
                'prev_hash' => is_string($modelHead->hash) ? $modelHead->hash : null,
                'global_prev_hash' => is_string($head->hash) ? $head->hash : null,
                'actor_type' => $actor['type'],
                'actor_id' => $actor['id'],
                'reason' => $reason,
                'context' => $context,
                'created_at' => $this->serializer->normalize($createdAt),
            ];

            $hash = $this->hasher->hash($envelope);

            $row = [
                'sequence' => $envelope['sequence'],
                'versionable_type' => $type,
                'versionable_id' => $id,
                'version' => $envelope['version'],
                'event' => $event,
                'hash_format' => $format,
                'schema_version' => $schemaVersion,
                'snapshot' => $this->serializer->encode($snapshot),
                'prev_hash' => $envelope['prev_hash'],
                'global_prev_hash' => $envelope['global_prev_hash'],
                'hash' => $hash,
                'actor_type' => $actor['type'],
                'actor_id' => $actor['id'],
                'reason' => $reason,
                'context' => $context === null ? null : $this->serializer->encode($context),
                'created_at' => $createdAt->format('Y-m-d H:i:s.u'),
            ];

            $row['id'] = $connection->table($this->table('versions'))->insertGetId($row);

            $this->advanceHead($connection, ChainName::GLOBAL, $envelope['sequence'], $hash, $row['created_at']);
            $this->advanceHead($connection, $chain, $envelope['version'], $hash, $row['created_at']);

            $version = (new Version)->newFromBuilder($row);

            $connection->afterCommit(fn () => event(new VersionRecorded($version, $model)));

            return $version;
        });
    }

    /**
     * The model's connection, after making sure the integrity tables live on it.
     */
    private function connectionFor(Model $model): Connection
    {
        $configured = config('model-integrity.connection');
        $integrityName = $this->database->connection(is_string($configured) ? $configured : null)->getName();
        $connection = $model->getConnection();

        if ($integrityName !== $connection->getName()) {
            throw IntegrityConfigurationException::connectionMismatch(
                $model,
                (string) $connection->getName(),
                (string) $integrityName,
            );
        }

        return $connection;
    }

    /**
     * @return object{sequence: mixed, hash: mixed}|null
     */
    private function lockHead(Connection $connection, string $chain): ?object
    {
        /** @var object{sequence: mixed, hash: mixed}|null */
        return $connection->table($this->table('heads'))
            ->where('chain', $chain)
            ->lockForUpdate()
            ->first(['sequence', 'hash']);
    }

    private function advanceHead(Connection $connection, string $chain, int $sequence, string $hash, string $updatedAt): void
    {
        $connection->table($this->table('heads'))
            ->where('chain', $chain)
            ->update(['sequence' => $sequence, 'hash' => $hash, 'updated_at' => $updatedAt]);
    }

    /**
     * The configured hash format, validated against the formats the Hasher knows.
     */
    private function hashFormat(): int
    {
        $format = config('model-integrity.hash_format', 1);

        if (! is_int($format)) {
            throw UnsupportedHashFormatException::for($format);
        }

        Hasher::fields($format);

        return $format;
    }

    private function table(string $name): string
    {
        return Config::string("model-integrity.tables.{$name}");
    }

    private function modelKey(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) && $key !== '' ? (string) $key : throw IntegrityConfigurationException::missingKey($model);
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
