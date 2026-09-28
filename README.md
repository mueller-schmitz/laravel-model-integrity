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
