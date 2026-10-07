# Laravel Model Integrity

[![Tests](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/tests.yml)
[![Static analysis](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/static-analysis.yml/badge.svg?branch=main)](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/static-analysis.yml)
[![Latest version](https://img.shields.io/packagist/v/mueller-schmitz/laravel-model-integrity)](https://packagist.org/packages/mueller-schmitz/laravel-model-integrity)
[![License](https://img.shields.io/packagist/l/mueller-schmitz/laravel-model-integrity)](LICENSE.md)

Immutable and versioned Eloquent models with a gapless, cryptographically verifiable history.

> **Status:** before 1.0 – the API may still change in minor versions.

## Scope

This package is **tamper-evident, not tamper-proof**:

- Changes through the application are either forbidden (`immutable`) or recorded as a new version (`versioned`).
- Changing recorded versions outside the application is blocked by database triggers and privileges. A database administrator can still bypass both, for example by dropping the triggers. Changing or removing single versions afterwards is **detected** by the verification, not prevented.
- The hash chain proves **integrity, not completeness**: operations that were never recorded are unknown to the chain. The state drift check compares the current model state against the last snapshot to surface such gaps.
- The chain is a plain SHA-256 chain without a secret. Whoever can write to the integrity tables can rewrite it consistently from any point on, and restoring an older backup yields a consistent but outdated history. [Anchors](#anchors) detect both, as far as they reach: an anchor attests the chain up to the moment it was created, outside the database. Versions recorded after the last anchor are only protected by the chain itself.

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

### Upgrading from 0.4

```bash
composer require mueller-schmitz/laravel-model-integrity:^0.5
php artisan model-integrity:install   # publishes the migration that adds integrity_files.key_id
php artisan migrate
```

Existing versions and files are unchanged and need no new baseline; the grants stay the same. Files stored so far remain unencrypted, see [Personal data in files](#personal-data-in-files).

**If you ran `model-integrity:snapshot` with 0.4.0** on models with `$integrityPersonal`: that command recorded their personal attributes in plain text. The versions it added (event `snapshot`, or `created` for rows that had no version) stay in the append-only history and are not affected by shredding. Find them with `select sequence, versionable_type, versionable_id from integrity_versions where event = 'snapshot'` and take them into account in your erasure concept. From 0.5 the command encrypts like every other recording.

### Upgrading from 0.3

```bash
composer require mueller-schmitz/laravel-model-integrity:^0.4
php artisan model-integrity:install   # publishes the migration for subject keys
php artisan migrate
php artisan model-integrity:grants    # use the same options as at installation
```

Existing versions are unchanged. Personal attributes are encrypted from the first version recorded after you declare them; versions recorded before keep them in plain text.

### Upgrading from 0.2

```bash
composer require mueller-schmitz/laravel-model-integrity:^0.3
php artisan model-integrity:install   # publishes only the new migrations for anchors
php artisan migrate
php artisan model-integrity:grants    # use the same options as at installation
```

Run the printed statements as an administrative user, then configure an anchor disk and schedule `model-integrity:anchor` (see [Anchors](#anchors)). The first anchor covers all existing versions; set `MODEL_INTEGRITY_ANCHORS_SINCE` to the upgrade date so that the time checks of attested proofs count those versions from then.

### Upgrading from 0.1

```bash
composer require mueller-schmitz/laravel-model-integrity:^0.2
php artisan model-integrity:install   # publishes only the new migrations for stored files
php artisan migrate
php artisan model-integrity:grants    # use the same options as at installation, e.g. --all-tables
```

Run the printed statements as an administrative user: with `--all-tables` the application user has no privileges on the new `integrity_files` table until then, and storing files fails.

Existing versions stay valid; the hash format is unchanged. A config file published with 0.1 keeps working: new keys (`files`, `actor`, `tables.files`) fall back to their defaults.

## Database enforcement

Model events are not the only way to change data. Queries like `Invoice::where(...)->update(...)` or `DB::table(...)` bypass them, and so does anyone with direct database access. Two database-level measures make recorded versions append-only:

### Triggers

The migrations install triggers that reject `UPDATE` and `DELETE` on `integrity_versions`, `integrity_files`, `integrity_anchors` and `integrity_anchor_proofs` (MySQL, MariaDB, PostgreSQL, SQLite). On PostgreSQL they reject `TRUNCATE` as well; on MySQL and MariaDB `TRUNCATE` fires no triggers and is prevented by privileges.

- MySQL with binary logging requires `SUPER` or `log_bin_trust_function_creators = 1` to create triggers (MySQL 8.4: the `SET_ANY_DEFINER` privilege).
- If the migration user may not create triggers, set `MODEL_INTEGRITY_APPEND_ONLY_TRIGGERS=false` and rely on privileges. `php artisan model-integrity:triggers` installs them later (`--remove` drops them).
- The head rows (`integrity_heads`) are updated on every write and have no trigger. A head that no longer matches the last version or anchor is reported by the verification.

### Privileges

The application's database user should only read and append versions, files and anchors, and read, add and update chain heads and subject keys. Print the matching SQL for your database:

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

Files are stored content-addressed: under their SHA-256 hash, once per content, never overwritten and never deleted. Every stored file is recorded as a version in the global chain. Files with personal data can be stored encrypted, see [Personal data in files](#personal-data-in-files).

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

`store()` accepts uploaded files, file objects, local paths and streams; seekable streams are read from their start. Assigning the attribute stores nothing by itself: store the file first and assign the result (or its hash).

- Files are written to `model-integrity.files.disk` (default `local`) under `integrity-files/{aa}/{bb}/{sha256}`. Use a private disk and restrict write access: a changed file on the disk is detected, not prevented.
- Disk name and path are recorded with every file and cannot change later. Configure a dedicated disk (e.g. `integrity`) from the start, so the storage behind it can be moved over the years without renaming the disk.
- Files are written to a temporary name and moved into place before the database records them, so an aborted upload never leaves a partial file under the final name, and the chain head is not locked during the upload. If a file with other content is found under the final name, it is kept as evidence (`….corrupt-{time}-{random}`), replaced with the correct content, and an `IntegrityViolationDetected` event is dispatched. A recorded file missing on the disk is restored when the same content is stored again, and an `IntegrityViolationDetected` event reports that it was missing. An existing file that cannot be read (permissions, disk errors) is never treated as corrupt: `store()` throws instead.
- Stored files use the morph alias `model-integrity.file`, which the package adds to the morph map; this works with `Relation::enforceMorphMap()`.
- Arrays and JSON of a model contain only the hash of a file attribute. Reading the attribute loads the stored file with one query per model, and so does serializing: Eloquent casts every attribute before `toArray()`/`toJson()`. For lists and API responses, hide the attribute (`$hidden`, `makeHidden()`) and expose the hash with `getRawOriginal()`; load files with `IntegrityFiles::find()`. If the file record is missing, the attribute and its serialized value are `null`; `checkModel()` reports it.
- `store()` reads any local path it is given: never pass paths from user input.
- If the database transaction rolls back after the file was written, the file stays on the disk without a record. It is harmless (the same content gets the same name) and not deleted automatically.
- Files are never deleted. Store a file that holds personal data encrypted with the key of its data subject (see [Personal data in files](#personal-data-in-files)); a file stored without a subject stays readable for good.

### Personal data and crypto-shredding

An append-only history conflicts with the right to erasure (GDPR art. 17). Declare the personal attributes of a model; they are recorded encrypted with a key per data subject, and shredding that key makes them unreadable in every version, while hashes, chains and anchors stay valid because they cover the ciphertext.

```php
class Order extends Model
{
    use HasIntegrity;

    protected array $integrityPersonal = ['shipping_name', 'shipping_address'];
    protected array $integrityAnonymized = ['shipping_name' => ''];   // allowed after shredding, besides null

    public function integritySubject(): ?Model   // default: the model itself
    {
        return $this->customer;
    }
}
```

```php
use MuellerSchmitz\ModelIntegrity\Facades\IntegritySubjects;

IntegritySubjects::shred($customer, 'Erasure request #42');   // or: php artisan model-integrity:shred "App\Models\Customer" 42 --reason="..."
IntegritySubjects::isShredded($customer);

$version->revealedSnapshot()->snapshot;   // personal attributes decrypted, null once shredded
$version->revealedSnapshot()->shredded;   // the attributes whose key was shredded
```

- A personal value is stored as `{"@encrypted": {"k": <key id>, "c": <ciphertext>}}` (AES-256-GCM over its canonical JSON); `null` stays `null`. The hash format is unchanged.
- Keys are stored in `integrity_subject_keys`, wrapped with the application key (`APP_PREVIOUS_KEYS` keeps old wraps readable). Creating and shredding a key are versions in the global chain (type `model-integrity.subject`); the key itself is never part of a snapshot.
- `model-integrity:shred` refuses a subject that never had a key (usually a typo in class or key); `--force` blocks such a subject from ever getting one.
- Shredding drops the key and keeps the row as a tombstone, so the subject never gets a new key. Afterwards only `null` or the declared anonymized values can be recorded for it; anything else throws a `ShreddedSubjectException`, so no plain personal data reaches the history.
- **Anonymize the rows yourself.** Shredding does not change your tables: retention periods and what to keep are decisions of your application. Verification reports personal attributes of shredded subjects that still hold data, and compares the other attributes as usual. Models in `immutable` mode cannot be updated; anonymize their rows with SQL, ideally in a documented maintenance step.
- `$integrityAnonymized` values are compared in the form a snapshot holds them (decimals as strings, dates as ISO strings); attributes with an `encrypted` cast can only be anonymized to `null`.
- Resolve the subject from the current attributes. Belongs-to relations whose foreign key changed are reloaded before the subject is resolved; verification reports versions encrypted with the key of another subject than the current one.
- If `integritySubject()` returns `null`, the model itself is the subject. Changing the morph class of a subject model (e.g. introducing a morph map) gives the same person a new key; shred under both names.
- `reason` and `context` are stored in plain text: never put personal data there. Versions recorded before the attributes were declared personal (or before 0.4) keep them in plain text and are not affected by shredding.
- Rotating `APP_KEY`: old wraps stay readable through `APP_PREVIOUS_KEYS`. Removing a previous key makes every key wrapped with it unreadable – the same as shredding all those subjects – and verification reports their personal data as unreadable.
- A key removed or cleared outside the package (without shredding) is reported, not taken for a shredding.
- **Limits:** the keys live in the same database, so backups contain them until those backups expire – shredding is complete only then. Logs, caches, queues, search indexes and copies outside the integrity tables are not covered. Files are covered only if they were stored for a subject (next section).

### Personal data in files

Files are never deleted either. Store a file that holds personal data for its data subject: it is encrypted with the subject's key before it is written to the storage disk, and shredding the key makes it unreadable.

```php
$file = IntegrityFiles::store($request->file('contract'), subject: $customer);   // a model, or '<morph class>:<key>'
$contract->update(['document' => $file]);

$file->isEncrypted();   // true
$file->contents();      // decrypted, like readStream()

IntegritySubjects::shred($customer, 'Erasure request #42');

$file->isShredded();    // true
$file->contents();      // throws a ShreddedSubjectException
```

- Encrypted files need the PHP extension `sodium`. Files stored without a subject do not.
- Hash, size and path describe the **encrypted** file as it lies on the disk, and that hash is what the file attribute of a model and its snapshots hold. Verification therefore needs no key and stays valid after shredding; the encrypted file remains on the disk.
- The key of an encrypted file (`key_id`) is part of the file's snapshot: a swapped or removed key is reported as `StateDrift`.
- Encrypted files are not stored once per content. Every `store()` encrypts with a new random salt, so equal files cannot be recognized by their hashes. Do not record the hash of the plain file elsewhere: for a document with little variation it would confirm a guess of its content.
- A shredded subject cannot get new files; `store()` throws a `ShreddedSubjectException`.
- Pass the subject that the models of this person use (the model `integritySubject()` returns, e.g. the customer and not the order), otherwise shredding the person misses the file. The package does not check this. A subject given as a string must have the form `<morph class>:<key>`.
- `readStream()` decrypts into a temporary stream (`php://temp`: in memory, beyond 2 MB in a temporary file that is removed when the stream is closed). MIME type and size are stored in plain text; the MIME type is detected from the plain file, the size is that of the encrypted file (45 bytes plus 17 bytes per 64 KiB more than the plain file).
- Files stored without a subject stay unencrypted and cannot be shredded later. Storing the content again with a subject adds an encrypted file, but the plain one remains: decide before the first upload.
- While a file is stored, its plain content lies in the temporary directory of the server: the upload itself, and a copy of a stream passed to `store()`. Both are removed at the end, but not when the process is killed; clean that directory as for any upload.
- `checkFiles()` and `verify --files` also open every encrypted file with its key. This reports a key that was replaced or removed in the database, which no snapshot would show.
- The limits above apply here as well: keys in backups, and copies outside the file store such as a download cache.

### File encryption format

Encrypted files use format `1`. A released format never changes; new rules always get a new format number. It is specified here so that a file can be decrypted without this package, given the key of its data subject:

| Bytes | Content |
|---|---|
| 4 | `MIFE` (ASCII) |
| 1 | format, `0x01` |
| 16 | salt, random per file |
| 24 | header of the libsodium secretstream |
| rest | encrypted chunks |

- File key: HKDF-SHA256 (RFC 5869) over the subject key (32 bytes) with the salt and the info `model-integrity/file/1`, 32 bytes long. The subject key is the value of `integrity_subject_keys.key`, decrypted with the application key (`Crypt::decryptString()`) and base64-decoded.
- Content: libsodium's `crypto_secretstream_xchacha20poly1305`, without additional data. The plain file is split into chunks of 65536 bytes; each becomes 17 bytes longer. The last chunk – the only one that may be shorter, and empty for an empty file – carries `TAG_FINAL`, all others `TAG_MESSAGE`. A file that ends without a final chunk or continues after it is invalid.

Reference files with their key are part of the test suite in the repository (`tests/Fixtures/file-format-1`).

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

A new nullable column needs no baseline if you list it in `$integrityOmitNull` before the migration runs (`protected array $integrityOmitNull = ['reference'];`): it stays out of the snapshot while it is `null`, so existing rows keep matching their last snapshot, and it is recorded as soon as it holds a value. This does not help for a column that was already recorded as `null`, and it cannot be combined with `$integrityPersonal`.

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
IntegrityChecker::checkAll();                          // global chain, anchors and every recorded model
IntegrityChecker::checkAnchors();                      // the anchors against the versions and their proofs
IntegrityChecker::checkFiles();                        // hashes the content of every stored file
```

The same is available on the model: `$invoice->history()`, `$invoice->verifyIntegrity()`, `$invoice->versionAt($date)`. A method with the same name defined on the model takes precedence over the trait.

Date strings passed to `versionAt()` are read in the application timezone.

### Command and scheduling

```bash
php artisan model-integrity:verify                                  # global chain, anchors and all recorded models
php artisan model-integrity:verify --model="App\Models\Invoice"     # one type (class or morph alias)
php artisan model-integrity:verify --model="App\Models\Invoice" --id=42
php artisan model-integrity:verify --fail-fast                      # stop at the first failing model
php artisan model-integrity:verify --files                          # also hash every stored file (all files, also with --model)
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
| `TruncatedChain` | A head (global or per model) does not match the last version: the end was cut off, the head is behind the last version, or it was removed; or an anchor attests versions beyond the end of the chain |
| `StateDrift` | The current row differs from the last snapshot, was deleted or restored outside the application, or was never recorded |
| `Unverifiable` | Versions exist whose model class is missing, does not use the trait, or was recorded under a former morph class |
| `FileMismatch` | A file referenced by any version is unknown, missing on its disk or has another size; with `checkFiles()`/`--files` also a changed content, or an encrypted file that does not open with the key of its data subject |
| `AnchorMismatch` | An anchor does not match the versions it attests, its proof, the previous anchor or the anchors head |

Every model check verifies that the files referenced by a model's versions exist with their recorded size. Hashing the content reads every file, so it only runs with `checkFiles()` or `verify --files`, for example weekly in the scheduler.

When a version is replaced and re-hashed, the successor no longer references it, so the replaced version is reported as `BrokenChain`. Violations dispatch an `IntegrityViolationDetected` event with the result (and the model for `checkModel()`). `lastValidVersion()` is only set by `checkModel()`.

Checks run in a read transaction with `REPEATABLE READ`, so versions recorded while a check runs do not produce false findings. Inside a caller's own transaction the caller's isolation level applies.

### Limits

- If the last versions **and** both heads (global and per model) are rewritten together, the chains are consistent again. Anchors detect it for the versions they cover; for versions after the last anchor only the state drift check notices it, if the model state differs. Anchor frequently.
- `checkAll()` discovers models through their recorded versions. Tables whose models never had a version are only checked by `checkType()`.
- `checkType()` keeps the recorded keys of the type in memory (roughly 50 MB per million models). Versions are streamed in chunks, so long histories do not.

## Anchors

The chain itself has no secret: an attacker with write access to the database can rewrite it consistently, recomputing every hash and head. An anchor prevents this from going unnoticed. It attests the versions recorded since the previous anchor in a place the database cannot change.

```bash
php artisan model-integrity:anchor                  # with the configured drivers
php artisan model-integrity:anchor --driver=disk    # with the given drivers only
```

```php
// routes/console.php
Schedule::command('model-integrity:anchor')->hourly()->withoutOverlapping();
Schedule::command('model-integrity:anchor-upgrade')->hourly()->withoutOverlapping(); // OpenTimestamps
```

Each run covers the global sequence from the end of the previous anchor to the current head, so every version is anchored exactly once. Without new versions nothing is anchored. Parallel runs are serialized by a lock on the `anchors` head row; recording writes are not blocked. If the chain has a gap, the run fails and nothing is kept.

Anchors are linked: each one contains the digest of the previous one. If a driver fails in one run, its next anchor attests the earlier ones through this link. The command keeps an anchor as long as one driver succeeded and exits with code `1` if any driver failed.

### Disk driver

```php
// config/model-integrity.php
'anchors' => [
    'drivers' => ['disk'],
    'disk' => [
        'disk' => env('MODEL_INTEGRITY_ANCHOR_DISK', 'local'),
        'path' => 'integrity-anchors',
    ],
],
```

The disk driver writes every statement as a file named `{to_sequence}-{digest}.json`; the SHA-256 hash of the file is the digest. The anchor only helps if whoever can change the database cannot change this disk: use storage on another system with its own credentials, ideally write-once (for example an S3 bucket with object lock in compliance mode). The default `local` disk is only suitable for trying it out.

Verification lists the files on the disk and checks each statement against the versions it covers, whether or not the database still contains that anchor. Deleting the anchor rows together with a rewritten chain is therefore detected as well. A statement left over by a failed run that matches the versions is not reported. Listing reads every statement file on each verification; on object storage that is one request per anchor.

### OpenTimestamps driver

```php
// config/model-integrity.php
'anchors' => [
    'drivers' => ['disk', 'opentimestamps'],
    'opentimestamps' => [
        'calendars' => [/* four public calendars by default */],
        'min_calendars' => 2,
        'timeout' => 10,
        'esplora_url' => env('MODEL_INTEGRITY_ESPLORA_URL', 'https://blockstream.info/api'),
    ],
    'max_delay_hours' => 72,
    'since' => env('MODEL_INTEGRITY_ANCHORS_SINCE'), // e.g. "2026-10-01" when upgrading
],
```

[OpenTimestamps](https://opentimestamps.org) anchors the digest in Bitcoin through public calendar servers – free, without an account. Only the 32-byte digest leaves your server. A run submits it to every configured calendar and keeps the answers as one standard `.ots` proof; it succeeds if at least `min_calendars` answered.

The proof is pending at first. Within a few hours the calendars commit to a Bitcoin block; `model-integrity:anchor-upgrade` then fetches the completed proof and stores it as a new proof row, keeping the previous one. Only the configured calendars are asked, never a URL taken from a stored proof.

Verification evaluates the proof and checks each Bitcoin attestation against the block header from an Esplora API (`esplora_url`: blockstream.info by default, mempool.space, or your own electrs/mempool instance to rely on no third party). Without that check, anyone who can write the proof could invent an attestation. If the block source cannot be reached, the proof is reported as `Unverifiable`, never accepted unchecked.

#### What the time proves

A Bitcoin attestation proves that the statement existed when the block was mined. A chain rewritten later can only get fresh proofs, so verification also requires:

- the proof was attested at most `max_delay_hours` after the versions it attests were recorded (their `created_at` is part of their hash and thereby anchored); a proof still pending after that time is reported as well;
- every version an anchor attests was recorded before the anchor (10 minutes of clock difference between servers are tolerated).

The time of the anchor row itself is not covered by any hash and is not relied on. Whoever rewrites old history gets only fresh proofs, which are too late for the recorded times – unless every rewritten version's `created_at` is moved to the time of the forgery as well. The rewritten history then claims that everything was recorded recently, which other records (documents, emails, backups) contradict; the package cannot detect that on its own. OpenTimestamps does not protect versions that are newer than the last confirmed anchor.

Versions recorded before anchoring was enabled cannot have been attested in time. Set `anchors.since` to the date you enabled anchoring (for example when upgrading an application that already has versions): such versions then count from that date, so their first anchor must be attested within `max_delay_hours` after it. A scheduler outage longer than `max_delay_hours` is reported, because the versions recorded during it were not attested in time.

#### Checking without this package

```bash
php artisan model-integrity:anchor-export 42 ./audit
ots verify ./audit/00000000000000001234-<digest>.json.ots
```

`model-integrity:anchor-export` writes the statement of an anchor and its proof files. The statement file's SHA-256 hash is the anchored digest, so the standard `ots` client verifies it against your own Bitcoin node. Recompute the Merkle root from the version hashes (see [Anchor format](#anchor-format)) to tie the statement to the versions.

### RFC 3161 driver

```php
// config/model-integrity.php
'anchors' => [
    'drivers' => ['disk', 'opentimestamps', 'rfc3161'],
    'rfc3161' => [
        'url' => env('MODEL_INTEGRITY_TSA_URL'),            // e.g. https://freetsa.org/tsr
        'ca_file' => env('MODEL_INTEGRITY_TSA_CA_FILE'),    // PEM file with the TSA's CA certificates
        'intermediates_file' => null,
        'policy' => null,                                   // require a TSA policy OID
        'timeout' => 10,
        'headers' => [],                                    // e.g. credentials of a commercial TSA
    ],
],
```

A time-stamp authority (TSA) signs that the statement's digest existed at a time. [freetsa.org](https://freetsa.org) is free; a qualified trust service provider under eIDAS gives the time-stamp legal weight in the EU. The time-stamp is there at once, no upgrade needed. The driver needs the PHP extension `openssl`.

The proof is the TSA's complete response. Verification checks the signature over the time-stamp, that it has exactly one signer, that it is for the statement's digest (and, if `policy` is set, issued under that policy, which is also requested), and that the TSA certificate is meant for time stamping only (critical extended key usage) and leads to a certificate in `ca_file`, possibly through CA certificates in `intermediates_file` – all valid at the time of the time-stamp, so proofs stay verifiable after the TSA certificate expired. Revocation (CRL, OCSP) and the ESS signing-certificate attribute are not checked; the signer is the certificate the signature names. The time of the time-stamp is subject to the same checks as Bitcoin attestations (see [What the time proves](#what-the-time-proves)). A missing CA file or extension is reported as `Unverifiable`.

```bash
php artisan model-integrity:anchor-export 42 ./audit
openssl ts -verify -in ./audit/<file>.json.tsr -data ./audit/<file>.json -CAfile tsa-ca.pem
```

Add `-untrusted <intermediates>` if the TSA certificate is issued by an intermediate CA. `openssl` checks the chain at the current time; once the TSA certificate has expired, add `-attime <time of the time-stamp as Unix time>`.

### Restoring a backup

Restoring the database to an earlier state is, from the anchors' point of view, a cut-off chain: statements on the anchor disk attest versions the database no longer has (`TruncatedChain`), and once new versions reuse those sequence numbers, `AnchorMismatch`. This is intended – the anchors show that history was lost. To continue with a passing verification:

1. Keep the existing statement files as evidence of what was lost (with object lock they cannot be removed anyway) and document the restore.
2. Point `anchors.disk.path` to a new, empty directory on the same disk. The anchors in the restored database still reference their files under the old path, so their proofs keep verifying.
3. Run `model-integrity:anchor`.

### Isolation on PostgreSQL

Anchor runs wait for each other on the `anchors` head row. With the default `READ COMMITTED` isolation the waiting run then continues; if the connection is configured for `REPEATABLE READ` or `SERIALIZABLE`, PostgreSQL aborts it with a serialization error instead. Use `withoutOverlapping()` in the scheduler, or keep the default isolation for the anchor command.

### Custom drivers

A driver implements `MuellerSchmitz\ModelIntegrity\Anchoring\Contracts\Anchor` (`submit()` returns the proof to keep, `verify()` checks it and may return the time of the attestation) and optionally `ListsStatements`, `UpgradesProofs` and `ExportsProofs`. Register it in a service provider and add its name to `anchors.drivers`:

```php
use MuellerSchmitz\ModelIntegrity\Anchoring\AnchorManager;

app(AnchorManager::class)->extend('archive', fn () => new ArchiveAnchor);
```

### Anchor format

An anchor statement is the canonical JSON (see [Canonical JSON](#canonical-json)) of exactly these fields; its digest is the lowercase hex SHA-256 of that string. Format `1` never changes.

| Field | Content |
|---|---|
| `anchor_format` | `1` |
| `from_sequence`, `to_sequence` | the range of the global sequence, inclusive |
| `merkle_root` | Merkle root of the version hashes of the range, in sequence order |
| `prev_digest` | digest of the previous anchor, `null` for the first |

The Merkle root follows RFC 6962, section 2.1, over the 32-byte version hashes: a leaf is `sha256(0x00 || hash)`, a node `sha256(0x01 || left || right)`, and a list of `n > 1` leaves is split after the largest power of two smaller than `n`. Leaves are never duplicated.

```text
{"anchor_format":1,"from_sequence":1,"merkle_root":"0073e5dfb5d3c6f71fb0dc1db2f096e02a2d6fd6d7a59d23c100b15a8488dac4","prev_digest":null,"to_sequence":3}
```

## Auditor export

```bash
php artisan model-integrity:export storage/audit/2026                     # everything
php artisan model-integrity:export storage/audit/2026 --from=2026-01-01 --to=2026-12-31
php artisan model-integrity:export storage/audit/invoices --model="App\Models\Invoice"
php artisan model-integrity:export storage/audit/2026 --files --no-reveal --fetch-dtd
```

The export is a directory an auditor can check without this package – for example for the data access of the German tax authorities (GoBD, Z3):

| File | Content |
|---|---|
| `index.xml` | Description of the CSV tables in the GDPdU description standard, read by audit software such as IDEA |
| `versions.csv` | Every version: chain fields, the snapshot as hashed and, unless `--no-reveal`, the snapshot with personal data decrypted (`null` where the key was shredded) |
| `versions.jsonl` | The exact envelope of every version, to recompute its hash |
| `anchors.csv`, `anchor_proofs.csv`, `proofs/` | Anchors, their statements and proof files (`.ots`, `.tsr`) |
| `inclusion_proofs.csv` | A Merkle inclusion proof (RFC 6962) of every exported version in its anchor |
| `files.csv` | Metadata of the stored files (the files themselves are not exported) |
| `report.json`, `report.html` | The verification result (`checkAll()`, with `--files` also the file contents), the global sequence the export covers and data that could not be exported as stored |
| `SPEC.md` | The hash, anchor and inclusion proof formats, with a script to recompute every hash |
| `SHA256SUMS` | Checksums of all files (`sha256sum -c SHA256SUMS`) |

A period (`--from`, `--to`: a date covers the whole day in UTC, a date with time and offset is taken as given) or a model (`--model`) exports only those versions; their anchors are exported in full, and the inclusion proofs make each exported version checkable without the others. Versions newer than the last anchor have no inclusion proof.

Everything is read in one consistent view, up to the global head at the start of the export (named in the report), so versions recorded meanwhile are not half included. Tampered rows do not stop the export: they are exported as stored and listed in the report. The directory must not exist or be empty; the export is written next to it and moved into place when complete, so a failed export leaves nothing behind. The command exits with `1` if the verification found violations or data could not be exported as stored – the export is written anyway – and with `2` if it cannot be written.

The export contains personal data in plain text unless `--no-reveal`: it is created readable for its owner only (0700/0600). Hand it over on a protected medium and delete it when the audit is done. Set `model-integrity.export.supplier` (name and location of the company) for the `index.xml`.

**GDPdU DTD.** The standard requires `gdpdu-01-03-2019.dtd` next to `index.xml`. It is published by CaseWare (formerly Audicon) without a license notice, so it is not part of this package. Download it from [caseware.com/de/beschreibungsstandard](https://www.caseware.com/de/beschreibungsstandard) and set `MODEL_INTEGRITY_GDPDU_DTD` to its path, or let the command fetch it with `--fetch-dtd` (needs `ext-zip`). Only the unchanged published file is accepted (checked by its SHA-256). `--without-dtd` writes the export without it.

**GDPdU limits.** The CSV files are UTF-8 with `;`, CRLF and a header line. The standard does not define line breaks in fields, quotes inside text or empty values: line breaks are replaced by a space, quotes are doubled, and empty fields mean no value. Times are exported as text marked as time (standard 1.6), timestamps additionally as ISO 8601 with microseconds. `index.xml` is validated against the DTD in CI; the import into IDEA itself has not been tested.

**Procedure documentation.** `php artisan vendor:publish --tag=model-integrity-docs` publishes a German template of the procedure documentation (Verfahrensdokumentation) for the part of your procedure this package covers.

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
