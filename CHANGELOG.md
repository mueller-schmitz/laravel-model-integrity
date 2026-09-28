# Changelog

All notable changes to this package are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is below 1.0.0, minor releases may contain breaking changes.

## [Unreleased]

### Added

- Package scaffold: service provider, config, migrations for `integrity_versions` and `integrity_heads`
- Hash format 1: canonical JSON serialization (`CanonicalSerializer`) and SHA-256 envelope hashing (`Hasher`), specified in the README and pinned by reference vectors
