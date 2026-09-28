# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0.0, minor releases may contain breaking changes.

## [Unreleased]

## [0.1.1] - 2026-09-28

### Fixed

- `touch()` and `$touches` no longer throw on immutable models when only excluded attributes such as `updated_at` change
- Versions can no longer be created through Eloquent (`Version::create()`); only the recorder writes them
- Clear errors for models without a key (e.g. composite keys), for declared but uninitialized `$integrity*` properties, for invalid UTF-8 in JSON keys and for binary columns
- `model-integrity:verify --id` reports an unknown key instead of passing
- Custom snapshot event names are validated (1–32 lowercase letters, digits or underscores)

### Changed

- CI runs on `ubuntu-24.04` and with a German locale, so the locale independence of float formatting is actually tested

### Documentation

- Minimum versions MySQL 8.0 and MariaDB 10.11, re-encryption with `APP_PREVIOUS_KEYS`, global scopes on related models, the meaning of `TruncatedChain`, internal protected methods of `IntegrityChecker`

## [0.1.0] - 2026-09-28

First release.

### Added

- `HasIntegrity` trait: records `created`, `updated`, `deleted`, `restored`, `force_deleted` and `relation_synced` versions with full snapshots, in the same transaction as the model change
- Two hash chains: one per model and one global chain with a gapless sequence, serialized by locks on head rows (one global, one per model)
- `immutable` and `versioned` modes, `forbid` and `record` delete modes, excluded attributes, declared relations, snapshot schema versions
- Snapshots read from the stored row and normalized by model casts (decimals as strings, datetimes in UTC, plain dates as `Y-m-d`, sorted JSON, enums, JSON cast classes, encrypted attributes as ciphertext)
- Actor resolution (`ModelIntegrity::actingAs()`, reset after queue jobs), reason and context per write
- `recordIntegritySnapshot()` and `model-integrity:snapshot` to record a new baseline after schema changes
- Hash format 1: SHA-256 over a canonical JSON envelope, specified in the README and pinned by reference vectors
- `IntegrityChecker` (class and facade): `checkModel()`, `checkType()`, `checkChain()`, `checkAll()`, `getHistory()` with per-version `isValid()`, `versionAt()`; detects hash mismatches, broken chains, version and sequence gaps, truncated chains, state drift and unverifiable types; checks run with a consistent read view and stream versions in chunks
- Model shortcuts `history()`, `verifyIntegrity()`, `versionAt()`
- Events `VersionRecorded` (after commit) and `IntegrityViolationDetected`
- Append-only triggers on `integrity_versions` for MySQL, MariaDB, PostgreSQL and SQLite (`append_only_triggers` config option, `model-integrity:triggers` command)
- Commands `model-integrity:install`, `model-integrity:grants` and `model-integrity:verify` (exit code 1 on violations)
- Tested with PHP 8.3–8.5, Laravel 12 and 13, MySQL 8.0/8.4, MariaDB 10.11/11.4, PostgreSQL 14/17 and SQLite, including parallel writers, verification during writes and a database user restricted to the printed privileges

[Unreleased]: https://github.com/mueller-schmitz/laravel-model-integrity/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/mueller-schmitz/laravel-model-integrity/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mueller-schmitz/laravel-model-integrity/releases/tag/v0.1.0
