# Laravel Model Integrity

[![Tests](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/tests.yml)
[![Static analysis](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/static-analysis.yml/badge.svg?branch=main)](https://github.com/mueller-schmitz/laravel-model-integrity/actions/workflows/static-analysis.yml)
[![Latest version](https://img.shields.io/packagist/v/mueller-schmitz/laravel-model-integrity)](https://packagist.org/packages/mueller-schmitz/laravel-model-integrity)
[![License](https://img.shields.io/packagist/l/mueller-schmitz/laravel-model-integrity)](LICENSE.md)

Immutable and versioned Eloquent models with a gapless, cryptographically verifiable history.

> **Status:** early development (v0.1 in progress). Not ready for production use.

## Scope

This package is **tamper-evident, not tamper-proof**:

- Changes through the application are either forbidden (`immutable`) or recorded as a new version (`versioned`).
- Any manipulation outside the application (direct SQL, restored backups, edited rows) is **detected** during verification, not prevented.
- The hash chain proves **integrity, not completeness**: operations that were never recorded are unknown to the chain. The state drift check compares the current model state against the last snapshot to surface such gaps.

## Performance cost

All writes are appended to a single global chain. The chain head is locked with `SELECT ... FOR UPDATE`, which deliberately serialises recording writes across the whole application. This is the price for a gapless global sequence.

## Requirements

- PHP ^8.3
- Laravel ^12.0 or ^13.0
- MySQL, MariaDB or PostgreSQL (SQLite works for local testing, but without database-level enforcement)

## Installation

```bash
composer require mueller-schmitz/laravel-model-integrity
php artisan vendor:publish --tag=model-integrity-config
php artisan vendor:publish --tag=model-integrity-migrations
php artisan migrate
```

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
- Each version holds a **full snapshot**, read from the stored database row and normalized by the model casts (decimals as strings, dates in UTC, JSON sorted). Encrypted attributes are stored as ciphertext. Define casts for all attributes whose type matters; uncast values are stored as the database driver returns them.

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

The actor is reset after every queue job.

### Relations

`sync()`, `attach()` and `detach()` fire no model events. Declare the relation in `$integrityRelations` and record the change in the same transaction:

```php
DB::transaction(function () use ($post, $tagIds) {
    $post->tags()->sync($tagIds);
    $post->recordRelation('tags');
});
```

### History

```php
$invoice->integrityVersions()->get(); // query builder, oldest first
$invoice->history();                  // collection, see Verification
```

### Limits

The package records what goes through Eloquent model events. These bypass it and are **not** recorded:

- mass updates and deletes (`Invoice::where(...)->update(...)`, `DB::table(...)`)
- `saveQuietly()`, `Model::withoutEvents()`
- `increment()`/`decrement()` run outside the `save()` transaction (still recorded, but not atomically)
- a model class that overrides `save()` or `delete()` itself replaces the transactional wrapper of the trait

Such changes are not prevented, but they are detected: the state drift check compares the current row with the last snapshot.

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
```

The same is available on the model: `$invoice->history()`, `$invoice->verifyIntegrity()`, `$invoice->versionAt($date)`. A method with the same name defined on the model takes precedence over the trait.

Date strings passed to `versionAt()` are read in the application timezone.

### What is detected

| Error type | Meaning |
|---|---|
| `HashMismatch` | A version's content does not match its hash, or its hash format is unknown |
| `BrokenChain` | A version is not referenced by its successor (per model or globally) |
| `VersionGap` | Version numbers of a model are not consecutive |
| `SequenceGap` | The global sequence has a gap: versions were removed |
| `TruncatedChain` | The chain head does not match the last version: the end was cut off or the head was reset |
| `StateDrift` | The current row differs from the last snapshot, was deleted or restored outside the application, or was never recorded |

When a version is replaced and re-hashed, the successor no longer references it, so the replaced version is reported as `BrokenChain`. Violations dispatch an `IntegrityViolationDetected` event with the result (and the model for `checkModel()`).

### Limits

- If the last versions **and** the chain head are removed together, the chain itself is consistent again. Only the state drift check notices it if the model state differs. External anchors (planned for v0.3) close this gap.
- `checkAll()` discovers models through their recorded versions. Tables whose models never had a version are only checked by `checkType()`.
- `checkType()` keeps the recorded keys of the type in memory (roughly 50 MB per million models).

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
- Values: `null`, booleans, integers and strings only. Decimals and floats are stored as strings, dates as UTC ISO 8601 strings with microseconds.
- An empty object and an empty array are both encoded as `[]`.
- Binary (non UTF-8) values are not supported and must be excluded from snapshots.

### Recomputing a hash

These rules match the defaults of common JSON libraries. With Python, for an envelope stored in `envelope.json`:

```bash
python3 -c "import hashlib, json; e = json.load(open('envelope.json', encoding='utf-8')); print(hashlib.sha256(json.dumps(e, sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode('utf-8')).hexdigest())"
```

Reference envelopes with their canonical strings and hashes are part of the test suite in the repository (`tests/Fixtures/hash-format-1.json`).

## License

MIT. See [LICENSE.md](LICENSE.md).
