# Laravel Model Integrity

[![Tests](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/tests.yml)
[![Static analysis](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/static-analysis.yml/badge.svg?branch=main)](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/static-analysis.yml)
[![Latest version](https://img.shields.io/packagist/v/mueller-schmitz/laravel-model-integrity)](https://packagist.org/packages/mueller-schmitz/laravel-model-integrity)
[![License](https://img.shields.io/packagist/l/mueller-schmitz/laravel-model-integrity)](LICENSE.md)

Immutable and versioned Eloquent models with a gapless, cryptographically verifiable history.

> **Status:** v0.1 – the API may still change in minor versions before 1.0. Files, external anchors (OpenTimestamps, RFC 3161), crypto-shredding and an auditor export are planned.

## Scope

This package is **tamper-evident, not tamper-proof**:

- Changes through the application are either forbidden (`immutable`) or recorded as a new version (`versioned`).
- Changing recorded versions outside the application is blocked by database triggers and privileges. A database administrator can still bypass both, for example by dropping the triggers. Changing or removing single versions afterwards is **detected** by the verification, not prevented.
- The hash chain proves **integrity, not completeness**: operations that were never recorded are unknown to the chain. The state drift check compares the current model state against the last snapshot to surface such gaps.
- The chain is a plain SHA-256 chain without a secret. Whoever can write to both integrity tables can rewrite it consistently from any point on, and restoring an older backup yields a consistent but outdated history. Until external anchors exist (planned for v0.3), only the state drift check may notice such cases, and only if the model rows differ from the rewritten snapshots.

## Performance cost

All writes are appended to a single global chain. The chain head is locked with `SELECT ... FOR UPDATE`, which deliberately serialises recording writes across the whole application. This is the price for a gapless global sequence.

Each recorded write adds two locking reads (global head and model head), a read of the stored row, an insert and two updates. In the test suite, 8 parallel processes record roughly 250–450 versions per second on MySQL, MariaDB and PostgreSQL (GitHub Actions runners, database in a container). Measure with your own workload before using it on write-heavy tables.

The head locks are held until the surrounding transaction commits. Keep transactions that record versions short, and expect deadlocks when several transactions record versions of several models in different orders; the database aborts one of them, and the caller has to retry.

## Requirements

- PHP ^8.3
- Laravel ^12.0 or ^13.0
- MySQL 8.0+, MariaDB 10.11+ or PostgreSQL 11+ (SQLite works for local testing; it has triggers, but no users or privileges)

## Installation

```bash
composer require mueller-schmitz/laravel-model-integrity
php artisan model-integrity:install
php artisan migrate
```

`model-integrity:install` publishes the config and the migrations that are not published yet; running it again does not duplicate them. Prefer it over `vendor:publish --tag=model-integrity-migrations`, which copies all migrations again under new timestamps.

### Upgrading from 0.1

```bash
composer require mueller-schmitz/laravel-model-integrity:^0.2
php artisan model-integrity:install   # publishes only the new migrations for stored files
php artisan migrate
php artisan model-integrity:grants    # adds the privileges on integrity_files
```

Existing versions stay valid; the hash format is unchanged. A config file published with 0.1 keeps working: new keys (`files`, `actor`, `tables.files`) fall back to their defaults.

## Database enforcement

Model events are not the only way to change data. Queries like `Invoice::where(...)->update(...)` or `DB::table(...)` bypass them, and so does anyone with direct database access. Two database-level measures make recorded versions append-only:

### Triggers

The migrations install triggers that reject `UPDATE` and `DELETE` on `integrity_versions` (MySQL, MariaDB, PostgreSQL, SQLite). On PostgreSQL they reject `TRUNCATE` as well; on MySQL and MariaDB `TRUNCATE` fires no triggers and is prevented by privileges.

- MySQL with binary logging requires `SUPER` or `log_bin_trust_function_creators = 1` to create triggers (MySQL 8.4: the `SET_ANY_DEFINER` privilege).
- If the migration user may not create triggers, set `MODEL_INTEGRITY_APPEND_ONLY_TRIGGERS=false` and rely on privileges. `php artisan model-integrity:triggers` installs them later (`--remove` drops them).
- The head rows (`integrity_heads`) are updated on every write and have no trigger. A head that no longer matches the last version is reported by the verification.

### Privileges

The application's database user should only read and append versions. Print the matching SQL for your database:

```bash
php artisan model-integrity:grants --user=app
php artisan model-integrity:grants --user=app --host=10.0.0.% --all-tables  # MySQL/MariaDB
```

The command only prints the statements; review and run them with an administrative user.

- **MySQL/MariaDB:** privileges granted on the whole database (`GRANT ALL ON app.*`) cannot be narrowed per table. `--all-tables` prints a `REVOKE` for the database-wide privileges and table privileges for every table and view instead. Global privileges (`ON *.*`) and accounts with other hosts are not covered.
- **PostgreSQL:** the application user must not own the integrity tables; owners can change or drop them regardless of privileges. `--host` and `--all-tables` do not apply.
- Run migrations with a separate user that may alter the schema.

Triggers and privileges are tested against MySQL 8.0/8.4, MariaDB 10.11/11.4 and PostgreSQL 14/17: a user with exactly these privileges records, deletes and verifies through the package and is denied `UPDATE` and `DELETE` on versions.

## Usage

Add the `HasIntegrity` trait to a model:

```php
use Illuminate\Database\Eloquent\Model;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;

class Invoice extends Model
{
    use HasIntegrity;

    protected string $integrityMode = 'versioned';    // 'versioned' | 'immutable'
    protected string $integrityDeletes = 'record';    // 'forbid' | 'record'
    protected array $integrityExcept = ['updated_at']; // not part of snapshots
    protected array $integrityRelations = ['tags'];   // related keys are part of every snapshot
    protected int $integritySchemaVersion = 1;        // bump when the snapshot structure changes
}
```

All properties are optional; defaults come from `config/model-integrity.php`.

### What gets recorded

| Action | Version event |
|---|---|
| `create()` | `created` |
| `update()` / `save()` with changes to recorded attributes | `updated` |
| `delete()` with `$integrityDeletes = 'record'` | `deleted` |
| `restore()` (soft deletes) | `restored` |
| `forceDelete()` (soft deletes) | `force_deleted` |
| `recordRelation('tags')` | `relation_synced` |

- `save()` and `delete()` run in a database transaction: the model change and its version are committed together or not at all.
- In `immutable` mode any update throws an `ImmutableModelException`. With `$integrityDeletes = 'forbid'` (default) deletes throw as well.
- Saves that only change excluded attributes (e.g. `touch()`) do not create a version.
- Each version holds a **full snapshot**, read from the stored database row and normalized by the model casts (decimals as strings, dates in UTC, JSON sorted). Encrypted attributes are stored as ciphertext; with `APP_PREVIOUS_KEYS` set, Laravel re-encrypts them on every save, so each save records a new version even if the plain text is unchanged. Define casts for all attributes whose type matters; uncast values are stored as the database driver returns them.

### Reason, context and actor

```php
$invoice->withIntegrityReason('Customer complaint')
    ->withIntegrityContext(['ticket' => 'SUP-123'])
    ->update(['total' => '90.00']);
```

Reason and context apply to the next `save()` or `delete()` only.

The actor is the authenticated user. Where nobody is authenticated, for example in queue jobs or console commands, set it explicitly:

```php
use MuellerSchmitz\ModelIntegrity\ModelIntegrity;

ModelIntegrity::actingAs($user, fn () => $invoice->update([...]));
ModelIntegrity::actingAs('system'); // a label instead of a model
```

The actor is reset after every queue job. By default only the default guard is asked; set `model-integrity.actor.guards` (e.g. `['web', 'admin']`) to ask several guards in order.

### Files

Files are stored content-addressed: under their SHA-256 hash, once per content, never overwritten and never deleted. Every stored file is recorded as a version in the global chain.

```php
use MuellerSchmitz\ModelIntegrity\Casts\AsIntegrityFile;
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityFiles;

// Migration: $table->char('pdf', 64)->nullable();

class Invoice extends Model
{
    use HasIntegrity;

    protected function casts(): array
    {
        return ['pdf' => AsIntegrityFile::class];
    }
}

$invoice->update(['pdf' => IntegrityFiles::store($request->file('pdf'))]);

$invoice->pdf->sha256;      // the column holds the hash, which is part of the snapshot
$invoice->pdf->readStream();
$invoice->pdf->contents();
IntegrityFiles::find($sha256);
```

`store()` accepts uploaded files, file objects, local paths and streams. Assigning the attribute stores nothing by itself: store the file first and assign the result (or its hash).

- Files are written to `model-integrity.files.disk` (default `local`) under `integrity-files/{aa}/{bb}/{sha256}`. Use a private disk and restrict write access: a changed file on the disk is detected, not prevented.
- If the database transaction rolls back after the file was written, the file stays on the disk without a record. It is harmless (the same content gets the same name) and not deleted automatically.
- Files are never deleted. Personal data in files cannot be removed until crypto-shredding arrives (planned for v0.4); keep it in mind before storing such files.

### Relations

`sync()`, `attach()` and `detach()` fire no model events. Declare the relation in `$integrityRelations` and record the change in the same transaction. The snapshot lists the related keys as the relation query returns them, so global scopes of the related model apply: soft-deleting a related model removes its key from the current state, and the verification reports drift until the relation is recorded again.

```php
DB::transaction(function () use ($post, $tagIds) {
    $post->tags()->sync($tagIds);
    $post->recordRelation('tags');
});
```

On MySQL and MariaDB (`REPEATABLE READ`), the first non-locking read of a transaction fixes what the transaction sees, whichever table it reads. Load the model outside the transaction or with `lockForUpdate()`, and do not read other data first: otherwise the snapshot may miss changes other processes committed in the meantime, which the state drift check would later report.

### History

```php
$invoice->integrityVersions()->get(); // query builder, oldest first
$invoice->history();                  // collection, see Verification
```

### After schema changes

Every version stores a full snapshot. After a migration adds or removes a column, after a cast, `$integrityExcept` or `$integrityRelations` changes, the current rows no longer match their last snapshots and the verification reports `StateDrift` for every model. Record a new baseline:

```bash
php artisan model-integrity:snapshot --model="App\Models\Invoice" --reason="Added reference column"
php artisan model-integrity:snapshot --all
```

The command records a `snapshot` version for every model whose last snapshot has an older `$integritySchemaVersion` or no longer matches the row, and a `created` version for rows that have none. Bump `$integritySchemaVersion` with the change so the history shows when the structure changed. For a single model: `$invoice->recordIntegritySnapshot('schema_migrated', 'Added reference column')`.

Datetime columns without a timezone are interpreted in the application timezone when they are read. Do not change `app.timezone` after the first version was recorded, or every stored timestamp would drift. Plain `date` casts are stored as `Y-m-d` and are not affected.

Decide on the morph map before the first version is recorded: versions store the morph class, and a renamed class or alias leaves the old history under the old name (reported as `Unverifiable`).

### Limits

The package records what goes through Eloquent model events. These bypass it and are **not** recorded:

- mass updates and deletes (`Invoice::where(...)->update(...)`, `DB::table(...)`)
- `saveQuietly()`, `Model::withoutEvents()`
- `increment()`/`decrement()` run outside the `save()` transaction (still recorded, but not atomically)
- a model class that overrides `save()` or `delete()` itself replaces the transactional wrapper of the trait

Such changes are not prevented, but they are detected: the state drift check compares the current row with the last snapshot.

Updating a model whose row was deleted concurrently throws instead of recording a version for a missing row; lock rows that may be deleted (`lockForUpdate()`) before updating them.

Model and integrity tables must use the same database connection, otherwise both cannot be written in one transaction.

## Verification

```php
use MuellerSchmitz\ModelIntegrity\Facades\IntegrityChecker;

$result = IntegrityChecker::checkModel($invoice);

$result->passes();
$result->fails();
$result->errors();            // Collection<IntegrityError>
$result->checkedVersions();
$result->lastValidVersion();  // the chain is intact up to this version

IntegrityChecker::getHistory($invoice);                // Collection<Version>, oldest first
IntegrityChecker::getHistory($invoice, verify: true);  // each version with ->isValid()
IntegrityChecker::versionAt($invoice, '2026-03-01');   // version current at that moment
IntegrityChecker::checkType(Invoice::class);           // all invoices, including deleted ones
IntegrityChecker::checkChain();                        // the global chain over all models
IntegrityChecker::checkAll();                          // global chain and every recorded model
IntegrityChecker::checkFiles();                        // hashes the content of every stored file
```

The same is available on the model: `$invoice->history()`, `$invoice->verifyIntegrity()`, `$invoice->versionAt($date)`. A method with the same name defined on the model takes precedence over the trait.

Date strings passed to `versionAt()` are read in the application timezone.

### Command and scheduling

```bash
php artisan model-integrity:verify                                  # global chain and all recorded models
php artisan model-integrity:verify --model="App\Models\Invoice"     # one type (class or morph alias)
php artisan model-integrity:verify --model="App\Models\Invoice" --id=42
php artisan model-integrity:verify --fail-fast                      # stop at the first failing model
php artisan model-integrity:verify --files                          # also hash every stored file
php artisan model-integrity:verify -v                               # print each step
```

The command prints the violations and exits with code `1` if there are any, so it can fail a CI job or alert from the scheduler:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('model-integrity:verify')->dailyAt('03:00')->emailOutputOnFailure('it@example.com');
```

### What is detected

| Error type | Meaning |
|---|---|
| `HashMismatch` | A version's content does not match its hash, or its hash format is unknown |
| `BrokenChain` | A version is not referenced by its successor (per model or globally) |
| `VersionGap` | Version numbers of a model are not consecutive |
| `SequenceGap` | The global sequence has a gap: versions were removed |
| `TruncatedChain` | A head (global or per model) does not match the last version: the end was cut off, the head is behind the last version, or it was removed |
| `StateDrift` | The current row differs from the last snapshot, was deleted or restored outside the application, or was never recorded |
| `Unverifiable` | Versions exist whose model class is missing, does not use the trait, or was recorded under a former morph class |
| `FileMismatch` | A file referenced by any version is unknown, missing on its disk or has another size; with `checkFiles()`/`--files` also a changed content |

Every check verifies that the files referenced by a model's versions exist with their recorded size. Hashing the content reads every file, so it only runs with `checkFiles()` or `verify --files`, for example weekly in the scheduler.

When a version is replaced and re-hashed, the successor no longer references it, so the replaced version is reported as `BrokenChain`. Violations dispatch an `IntegrityViolationDetected` event with the result (and the model for `checkModel()`). `lastValidVersion()` is only set by `checkModel()`.

Checks run in a read transaction with `REPEATABLE READ`, so versions recorded while a check runs do not produce false findings. Inside a caller's own transaction the caller's isolation level applies.

### Limits

- If the last versions **and** both heads (global and per model) are rewritten together, the chains are consistent again. Only the state drift check notices it if the model state differs. External anchors (planned for v0.3) close this gap; see Scope.
- `checkAll()` discovers models through their recorded versions. Tables whose models never had a version are only checked by `checkType()`.
- `checkType()` keeps the recorded keys of the type in memory (roughly 50 MB per million models). Versions are streamed in chunks, so long histories do not.

## Hash format

Every version stores the hash format it was created with (`hash_format`). A released format never changes; new rules always get a new format number. This section specifies format `1` so that hashes can be recomputed independently of this package, for example by an auditor.

### Envelope

The hash is the lowercase hex SHA-256 of the canonical JSON encoding of this envelope, built from the stored version row:

| Field | Value |
|---|---|
| `format` | `1` (integer) |
| `sequence` | global sequence number (integer) |
| `versionable_type` | morph class of the model (string) |
| `versionable_id` | model key (string) |
| `version` | version per model, starting at 1 (integer) |
| `event` | `created`, `updated`, `deleted`, `restored`, `relation_synced` or a custom event (string) |
| `schema_version` | snapshot schema version (integer) |
| `snapshot` | full model state (object) |
| `prev_hash` | hash of the previous version of the same model, `null` for version 1 |
| `global_prev_hash` | hash of the previous entry of the global chain, `null` for sequence 1 |
| `actor_type`, `actor_id` | who made the change (string or `null`) |
| `reason` | reason for the change (string or `null`) |
| `context` | additional context (object or `null`) |
| `created_at` | UTC timestamp, e.g. `2026-09-28T10:05:00.123456Z` (string) |

No other fields are allowed. The database `id` is not part of the hash.

### Canonical JSON

- Object keys are sorted recursively by Unicode code point (equal to UTF-8 byte order); arrays keep their order.
- No insignificant whitespace.
- UTF-8 output. Only characters JSON requires are escaped: `"`, `\` and control characters below U+0020 (`\b`, `\t`, `\n`, `\f`, `\r`, otherwise lowercase `\u00xx`). Slashes, non-ASCII characters and U+2028/U+2029 are not escaped.
- Values: `null`, booleans, integers and strings only. Decimals are stored as strings with their scale, floats as their shortest round-trip decimal string (like `JSON.stringify`: fixed notation for exponents from -7 to 20, otherwise `1.5E+25`), datetimes as UTC ISO 8601 strings with microseconds, plain dates as `Y-m-d`.
- An empty object and an empty array are both encoded as `[]`.
- Binary (non UTF-8) values are not supported and must be excluded from snapshots.

### Recomputing a hash

These rules match the defaults of common JSON libraries. The stored `snapshot` and `context` columns must be canonicalized first (MySQL's JSON type reorders keys on storage); the package's `Version::toEnvelope()` does that. With Python, for an envelope stored in `envelope.json`:

```bash
python3 -c "import hashlib, json; e = json.load(open('envelope.json', encoding='utf-8')); print(hashlib.sha256(json.dumps(e, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode('utf-8')).hexdigest())"
```

Reference envelopes with their canonical strings and hashes are part of the test suite in the repository (`tests/Fixtures/hash-format-1.json`).

## Versioning

The package follows [Semantic Versioning](https://semver.org). Before 1.0, minor versions may contain breaking changes; they are listed in the [changelog](CHANGELOG.md).

Hash formats are independent of package versions: a released hash format never changes, so versions recorded with any release stay verifiable with every later release.

## Testing

```bash
composer test      # Pest, SQLite in memory by default
composer analyse   # PHPStan, level max
composer lint      # Pint
```

Run the suite against another database with the usual `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` environment variables. The trigger, privilege and concurrency tests only run on MySQL, MariaDB and PostgreSQL; the privilege tests create and drop the database user `mi_restricted` and need an administrative account. The suite is not meant for `pest --parallel`: the concurrency workers and the privilege tests share one database and one user.

## License

MIT. See [LICENSE.md](LICENSE.md).
