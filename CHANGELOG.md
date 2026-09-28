# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0.0, minor releases may contain breaking changes.

## [Unreleased]

### Added

- Package scaffold: service provider, config, migrations for `integrity_versions` and `integrity_heads`
- `HasIntegrity` trait: records `created`, `updated`, `deleted`, `restored`, `force_deleted` and `relation_synced` versions with full snapshots in the per-model and the global hash chain, inside the model's save/delete transaction
- `immutable` and `versioned` modes, `forbid` and `record` delete modes
- Snapshots read from the stored row and normalized by model casts; declared relations included
- Actor resolution (`ModelIntegrity::actingAs()`), reason and context per write
- `VersionRecorded` event after commit
- `IntegrityChecker` (class and facade): `checkModel()`, `checkType()`, `checkChain()`, `checkAll()`, `getHistory()` with per-version `isValid()`, `versionAt()`; detects hash mismatches, broken chains, version and sequence gaps, truncated chains and state drift; `IntegrityViolationDetected` event
- Model shortcuts `history()`, `verifyIntegrity()`, `versionAt()`
- Append-only triggers on `integrity_versions` for MySQL, MariaDB, PostgreSQL and SQLite, installed by migration (`append_only_triggers` config option)
- `model-integrity:install`, `model-integrity:grants` (privileges for the application user, `--all-tables` for MySQL/MariaDB) and `model-integrity:verify` (exit code 1 on violations, `--model`, `--id`, `--fail-fast`)
- `stopOnFirstFailure` option for `checkType()` and `checkAll()`
- Hash format 1: canonical JSON serialization (`CanonicalSerializer`) and SHA-256 envelope hashing (`Hasher`), specified in the README and pinned by reference vectors
