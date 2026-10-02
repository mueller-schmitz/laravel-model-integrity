# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0.0, minor releases may contain breaking changes.

## [Unreleased]

### Added

- Crypto-shredding: personal attributes (`$integrityPersonal`) are recorded encrypted (AES-256-GCM) with a key per data subject (`integritySubject()`); hashes cover the ciphertext, so shredding the key leaves chains and anchors valid
- `IntegritySubjects::shred()` / `model-integrity:shred` drop a subject's key for good (tombstone); afterwards only `null` or `$integrityAnonymized` values can be recorded for the subject
- Table `integrity_subject_keys`; creating and shredding keys are versions in the global chain (type `model-integrity.subject`)
- `Version::revealedSnapshot()` decrypts personal attributes where the key still exists
- Verification compares personal attributes decrypted and reports personal data left in rows of shredded subjects
- Auditor export `model-integrity:export`: GDPdU tables (`index.xml` + CSV) for the data access of the German tax authorities, exact version envelopes (JSON lines), anchors with proof files, Merkle inclusion proofs per version, verification report (JSON, HTML), format specification and checksums; filters by period and model; the GDPdU DTD is read from a configured path or downloaded and checked by its SHA-256
- `MerkleTree::auditPath()` and `verifyInclusion()` (RFC 6962 / RFC 9162)
- German template of the procedure documentation (`vendor:publish --tag=model-integrity-docs`)

### Fixed

- Verification reports versions whose stored snapshot is no valid JSON, and stored hashes that are not lowercase hex, instead of failing on them

### Changed

- `GrantStatements::build()` takes tables that may be read, added to and updated (`updatableTables`); `model-integrity:grants` includes the subject keys table

## [0.3.0] - 2026-10-02

### Added

- Anchors: `model-integrity:anchor` attests the versions recorded since the last anchor outside the database. A statement (anchor format 1, specified in the README) holds the range of the global sequence, the RFC 6962 Merkle root of its version hashes and the digest of the previous anchor; drivers anchor its SHA-256 digest
- Disk anchor driver: writes each statement as a file to a separate disk; verification also checks statements on the disk that the database no longer contains
- Custom anchor drivers via `AnchorManager::extend()`
- OpenTimestamps anchor driver: own implementation of the `.ots` format, submits to four public calendars (at least two must answer), checks Bitcoin attestations against block headers from an Esplora API (blockstream.info by default, configurable); unreachable block source is reported as `Unverifiable`
- `model-integrity:anchor-upgrade` completes pending proofs and stores them as new proof rows; only configured calendars are asked
- RFC 3161 anchor driver for time-stamp authorities (e.g. freetsa.org or a qualified trust service provider): own DER encoding of requests and responses, nonce and digest checks, signature check via ext-openssl and a certificate chain check at the time of the time-stamp; `ext-openssl` is suggested
- `model-integrity:anchor-export` writes an anchor's statement and proof files, e.g. for `ots verify`
- Time checks for attested proofs: attested or still pending more than `anchors.max_delay_hours` (72) after the versions they attest were recorded (or after `anchors.since` for versions recorded before anchoring was enabled), or versions recorded after their anchor, are reported as `AnchorMismatch`
- Append-only tables `integrity_anchors` and `integrity_anchor_proofs` with triggers and privileges; the `anchors` head row serializes anchor runs
- Error type `AnchorMismatch`; `checkAnchors()`, also part of `checkAll()` and `model-integrity:verify`. An anchor beyond the end of the chain is reported as `TruncatedChain`

### Changed

- `model-integrity:triggers` and `model-integrity:grants` cover the anchor tables
- Requires `illuminate/http` (HTTP client for the OpenTimestamps and RFC 3161 drivers)
- `GrantStatements::build()` takes a list of further append-only tables (`appendOnlyTables`) instead of `filesTable`

## [0.2.0] - 2026-09-29

### Added

- Files in the chain: `IntegrityFiles::store()` stores files content-addressed under their SHA-256 hash on a configurable disk, once per content, written atomically outside the chain lock; a file with other content found under the final name is kept as evidence and replaced, a recorded file missing on the disk is restored, both reported by `IntegrityViolationDetected`; every stored file (`StoredFile`, morph alias `model-integrity.file`) is a version in the global chain
- `AsIntegrityFile` cast: a column holding the file hash, so the file is part of the model's snapshot
- Error type `FileMismatch`; `checkModel()` checks referenced files for presence and size, `checkFiles()` and `verify --files` hash their content
- Append-only triggers and privileges for `integrity_files`; `model-integrity:triggers` covers both tables
- `model-integrity.actor.guards` to ask several auth guards for the actor
- `verify -v` prints each step; `checkAll()` accepts a progress callback

### Changed

- `model-integrity:install` publishes only migrations that are not published yet, dated after the existing ones, so upgrading does not duplicate migrations
- `model-integrity:triggers` fails with a hint to migrate when no integrity table exists
- The trigger message is now "Integrity records are append-only." for both tables

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

[Unreleased]: https://github.com/mueller-schmitz/laravel-model-integrity/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/mueller-schmitz/laravel-model-integrity/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/mueller-schmitz/laravel-model-integrity/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/mueller-schmitz/laravel-model-integrity/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/mueller-schmitz/laravel-model-integrity/releases/tag/v0.1.0
