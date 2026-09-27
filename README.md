# Laravel Model Integrity

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

## License

MIT. See [LICENSE.md](LICENSE.md).
