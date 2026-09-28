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
- Hash format 1: canonical JSON serialization (`CanonicalSerializer`) and SHA-256 envelope hashing (`Hasher`), specified in the README and pinned by reference vectors
