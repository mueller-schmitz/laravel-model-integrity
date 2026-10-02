<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Shredding;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use MuellerSchmitz\ModelIntegrity\Exceptions\IntegrityConfigurationException;
use MuellerSchmitz\ModelIntegrity\Exceptions\ShreddedSubjectException;
use MuellerSchmitz\ModelIntegrity\Models\DataSubject;
use MuellerSchmitz\ModelIntegrity\Recording\ChainName;
use UnexpectedValueException;

/**
 * Keys of data subjects, wrapped with the application key. Shredding drops
 * the key and keeps the row as a tombstone, so a shredded subject never
 * gets a new key. Each subject is a DataSubject model: creating and
 * shredding its key are recorded in the chain.
 *
 * Create keys before a recorder holds the chain head (keyFor() records a
 * version of its own); HasIntegrity does so before recording.
 *
 * Bound per request or job: unwrapped keys are kept only that long.
 */
class SubjectKeys
{
    /** Length of the `subject` column. */
    private const int MAX_SUBJECT_LENGTH = 191;

    /** @var array<string, string|null> unwrapped keys by id, null if shredded or unknown */
    private array $keys = [];

    /**
     * The subject's key, created on first use.
     *
     * @throws ShreddedSubjectException if the subject was shredded
     */
    public function keyFor(string $subject): SubjectKey
    {
        $name = $this->name($subject);
        $row = $this->row($name) ?? $this->create($name);

        if ($row['key'] === null) {
            throw ShreddedSubjectException::noKey($subject);
        }

        $key = new SubjectKey($row['id'], $this->unwrap($row['key']));
        $this->keys[$key->id] = $key->key;

        return $key;
    }

    /**
     * The raw key with this id, null if it was shredded or never existed.
     */
    public function find(string $id): ?string
    {
        if (array_key_exists($id, $this->keys)) {
            return $this->keys[$id];
        }

        $wrapped = $this->connection()->table($this->table())->where('id', $id)->value('key');

        return $this->keys[$id] = is_string($wrapped) ? $this->unwrap($wrapped) : null;
    }

    /**
     * Whether a key with this id is usable, was shredded, or is missing
     * although it was never shredded (removed outside the package).
     *
     * @return 'active'|'shredded'|'missing'
     */
    public function state(string $id): string
    {
        $row = $this->connection()->table($this->table())->where('id', $id)->first(['key', 'shredded_at']);

        return match (true) {
            $row === null => 'missing',
            $row->key !== null => 'active',
            $row->shredded_at !== null => 'shredded',
            default => 'missing',
        };
    }

    /**
     * The id of the subject's key row, also after shredding; null if the subject never had one.
     */
    public function keyIdFor(string $subject): ?string
    {
        return $this->row($this->name($subject))['id'] ?? null;
    }

    public function exists(string $subject): bool
    {
        return $this->row($this->name($subject)) !== null;
    }

    public function isShredded(string $subject): bool
    {
        $row = $this->row($this->name($subject));

        return $row !== null && $row['key'] === null;
    }

    /**
     * Drops the subject's key for good. The change is recorded as a version
     * of the subject in the global chain.
     *
     * @return string|null the id of the dropped key, null if there was none (left as tombstone)
     */
    public function shred(string $subject, ?string $reason = null): ?string
    {
        $name = $this->name($subject);
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $name, $reason): ?string {
            // Same lock order as recorders and key creation: chain head first.
            $this->lockGlobalHead($connection);
            $model = DataSubject::query()->where('subject', $name)->lockForUpdate()->first();
            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');

            if ($model === null) {
                $this->create($name, withKey: false, reason: $reason);

                return null;
            }

            if ($model->key === null) {
                return null;
            }

            $model->withIntegrityReason($reason)->update(['key' => null, 'shredded_at' => $now]);
            $this->keys[$model->id] = null;

            return $model->id;
        });
    }

    /**
     * @return array{id: string, key: string|null}|null
     */
    private function row(string $name): ?array
    {
        return $this->typed($this->connection()->table($this->table())->where('subject', $name)->first(['id', 'key']));
    }

    /**
     * @return array{id: string, key: string|null}|null
     */
    private function typed(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $id = $row->id ?? null;
        $key = $row->key ?? null;

        if (! is_string($id) || ($key !== null && ! is_string($key))) {
            throw new UnexpectedValueException('A data subject row is malformed.');
        }

        return ['id' => $id, 'key' => $key];
    }

    /**
     * Creates the subject, which records its first version.
     *
     * The global chain head is locked first, as every recorder does, so
     * creators and recorders always lock in the same order: a creator never
     * holds the subject's unique entry while waiting for the head. Under the
     * head lock, a subject a parallel process created is found with a read
     * that sees committed rows.
     *
     * @return array{id: string, key: string|null}
     */
    private function create(string $name, bool $withKey = true, ?string $reason = null): array
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $name, $withKey, $reason): array {
            $this->lockGlobalHead($connection);

            if (($existing = $this->committedRow($connection, $name)) !== null) {
                return $existing;
            }

            $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u');
            $model = (new DataSubject)->forceFill([
                'subject' => $name,
                'key' => $withKey ? Crypt::encryptString(base64_encode(random_bytes(32))) : null,
                'created_at' => $now,
                'shredded_at' => $withKey ? null : $now,
            ]);

            try {
                // save() runs in its own transaction (a savepoint): on PostgreSQL a
                // failed insert would otherwise abort the caller's transaction.
                $model->withIntegrityReason($reason)->save();
            } catch (UniqueConstraintViolationException $e) {
                return $this->committedRow($connection, $name) ?? throw $e;
            }

            return ['id' => $model->id, 'key' => $model->key];
        });
    }

    private function lockGlobalHead(Connection $connection): void
    {
        $head = $connection->table(Config::string('model-integrity.tables.heads'))
            ->where('chain', ChainName::GLOBAL)
            ->lockForUpdate()
            ->first();

        if ($head === null) {
            throw IntegrityConfigurationException::headMissing(ChainName::GLOBAL);
        }
    }

    /**
     * The subject's latest committed row, regardless of the transaction's
     * snapshot: a shared lock on MySQL/MariaDB (needs SELECT only), a plain
     * read on PostgreSQL, whose default READ COMMITTED sees committed rows.
     *
     * @return array{id: string, key: string|null}|null
     *
     * @phpstan-impure
     */
    private function committedRow(Connection $connection, string $name): ?array
    {
        $query = $connection->table($this->table())->where('subject', $name);

        return $this->typed((in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? $query->sharedLock() : $query)->first(['id', 'key']));
    }

    private function unwrap(string $wrapped): string
    {
        try {
            $key = base64_decode(Crypt::decryptString($wrapped), true);
        } catch (DecryptException $e) {
            throw new DecryptException('A data subject key cannot be unwrapped with the application key (or APP_PREVIOUS_KEYS).', previous: $e);
        }

        if ($key === false || strlen($key) !== 32) {
            throw new DecryptException('A data subject key is malformed.');
        }

        return $key;
    }

    /**
     * The stored subject name, hashed when it exceeds the column.
     */
    private function name(string $subject): string
    {
        return strlen($subject) <= self::MAX_SUBJECT_LENGTH ? $subject : 'subject#'.hash('sha256', $subject);
    }

    private function connection(): Connection
    {
        $configured = config('model-integrity.connection');

        /** @var Connection */
        return DB::connection(is_string($configured) ? $configured : null);
    }

    private function table(): string
    {
        return Config::string('model-integrity.tables.subject_keys', 'integrity_subject_keys');
    }
}
