# Specification of the recorded history

This export was written by `mueller-schmitz/laravel-model-integrity`. This file specifies the formats, so that everything can be checked without the package.

## Files of the export

| File | Content |
|---|---|
| `index.xml`, `gdpdu-01-03-2019.dtd` | Description of the CSV tables for audit software (GDPdU description standard) |
| `versions.csv` | Recorded versions; `snapshot` exactly as hashed, `snapshot_revealed` with decrypted personal data (if exported; `null` where the key was shredded) |
| `versions.jsonl` | One line per version: `{"envelope": …, "hash": …, "sequence": …}` – the exact input of each hash |
| `anchors.csv`, `proofs/*.json` | Anchors and their canonical statements |
| `anchor_proofs.csv`, `proofs/*.ots`, `proofs/*.tsr` | Proofs of the anchors (OpenTimestamps, RFC 3161) |
| `inclusion_proofs.csv` | Merkle inclusion proof of every exported version in its anchor |
| `files.csv` | Stored files (content-addressed by SHA-256) |
| `report.json`, `report.html` | Verification result at the time of the export, the global sequence the export covers (`head_sequence`) and data that could not be exported as stored (`export_problems`) |
| `SHA256SUMS` | SHA-256 of every file (`sha256sum -c SHA256SUMS`) |

CSV files are UTF-8 with a header line, `;` between fields, CRLF between records, text in double quotes (quotes doubled), `,` as decimal symbol and empty fields for no value. Line breaks in text are replaced by a space; `versions.jsonl` keeps the exact values.

## Version hash (format 1)

The hash is the lowercase hex SHA-256 of the canonical JSON encoding of the envelope (`envelope` in `versions.jsonl`), with exactly these fields: `format` (1), `sequence`, `versionable_type`, `versionable_id`, `version`, `event`, `schema_version`, `snapshot`, `prev_hash`, `global_prev_hash`, `actor_type`, `actor_id`, `reason`, `context`, `created_at` (UTC, `2026-09-28T10:05:00.123456Z`).

Canonical JSON:

- Object keys sorted recursively by Unicode code point; arrays keep their order; an empty object is encoded as `[]`.
- No insignificant whitespace; UTF-8; only the escapes JSON requires (`"`, `\`, control characters below U+0020); slashes, non-ASCII characters and U+2028/U+2029 are not escaped.
- Values are `null`, booleans, integers and strings: decimals and floats as strings, datetimes as UTC ISO 8601 with microseconds, plain dates as `Y-m-d`.

This equals Python's `json.dumps(envelope, sort_keys=True, separators=(',', ':'), ensure_ascii=False)`:

```bash
python3 -c "import hashlib, json, sys
for line in open('versions.jsonl', encoding='utf-8'):
    r = json.loads(line)
    h = hashlib.sha256(json.dumps(r['envelope'], sort_keys=True, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()
    print(r['sequence'], 'ok' if h == r['hash'] else 'MISMATCH')"
```

Chains: `prev_hash` is the hash of the previous version of the same model (`null` for version 1), `global_prev_hash` the hash of the previous sequence (`null` for sequence 1). Sequences are gapless from 1, versions per model gapless from 1.

## Personal data

A personal attribute is stored as `{"@encrypted": {"k": "<key id>", "c": "<ciphertext>"}}`: AES-256-GCM (Laravel encrypter payload, base64) over the canonical JSON of the value, with the key of its data subject. The hash covers this form, so hashes stay valid after a key was shredded.

## Anchors (format 1)

An anchor statement (`proofs/<to_sequence>-<digest>.json`) is the canonical JSON of `anchor_format` (1), `from_sequence`, `to_sequence`, `merkle_root` and `prev_digest` (digest of the previous anchor, `null` for the first). Its digest is the lowercase hex SHA-256 of the file.

The Merkle root follows RFC 6962, section 2.1, over the 32-byte version hashes of the range in sequence order: a leaf is `SHA-256(0x00 || hash)`, a node `SHA-256(0x01 || left || right)`, and a list of `n > 1` leaves is split after the largest power of two smaller than `n`.

Proof files attest the digest:

- `*.ots`: OpenTimestamps; `ots verify proofs/<statement>.json.ots` checks it against Bitcoin (needs access to a Bitcoin node or block explorer). A proof that was still pending at the time of the export has no Bitcoin attestation yet.
- `*.tsr`: RFC 3161 time-stamp response; `openssl ts -verify -in proofs/<statement>.json.tsr -data proofs/<statement>.json -CAfile <CA certificates of the TSA>`, with `-untrusted <intermediate certificates>` if the TSA certificate is issued by an intermediate CA, and `-attime <time of the time-stamp as Unix time>` once the TSA certificate has expired.

## Inclusion proofs

`inclusion_proofs.csv` gives, per version, its anchor, its `leaf_index` (position in the anchored range, from 0), the `tree_size` (versions in the range) and the `audit_path` (RFC 6962, section 2.1.1: sibling hashes from the leaf up). Verify it against the anchor's `merkle_root` (RFC 9162, section 2.1.3.2):

1. `fn = leaf_index`, `sn = tree_size - 1`, `r = SHA-256(0x00 || version hash)`.
2. For each `p` in the path: if `sn == 0`, fail. If `fn` is odd or `fn == sn`: `r = SHA-256(0x01 || p || r)`, then, if `fn` is even, shift `fn` and `sn` right until `fn` is odd or 0. Otherwise `r = SHA-256(0x01 || r || p)`. Then shift `fn` and `sn` right once.
3. The proof holds if `sn == 0` and `r` equals the Merkle root.

Do not take `leaf_index` and `tree_size` from the CSV on trust: check `leaf_index == sequence - from_sequence` and `tree_size == to_sequence - from_sequence + 1` with the anchor from `anchors.csv`, and the anchor's statement file against its digest and proof file.

Versions recorded after the last anchor have no inclusion proof. An export limited to a period or a model contains only some versions: their chains (`prev_hash`, `global_prev_hash`) can only be followed within the export, while each version is still proven by its inclusion proof and anchor. The anchors themselves (`prev_digest`) are complete only in a full export.
