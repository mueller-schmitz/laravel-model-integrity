<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

/**
 * The tables of an auditor export, as described in its index.xml.
 */
final class Tables
{
    /**
     * @return array{versions: Table, inclusion_proofs: Table, anchors: Table, anchor_proofs: Table, files: Table}
     */
    public static function all(bool $reveal): array
    {
        $versionColumns = [
            Column::numeric('sequence', 'Position in the global chain', primaryKey: true),
            Column::text('versionable_type', 'Model type (class or morph alias)'),
            Column::text('versionable_id', 'Model key'),
            Column::numeric('version', 'Version of the model, from 1'),
            Column::text('event', 'Recorded event'),
            Column::date('created_date', 'Date recorded (UTC)'),
            Column::time('created_time', 'Time recorded (UTC)'),
            Column::text('created_at', 'Time recorded, ISO 8601 with microseconds (UTC), as hashed'),
            Column::text('actor_type', 'Type of the acting user'),
            Column::text('actor_id', 'Key of the acting user'),
            Column::text('reason', 'Reason given for the change'),
            Column::numeric('hash_format', 'Hash format'),
            Column::numeric('schema_version', 'Snapshot schema version'),
            Column::text('hash', 'SHA-256 hash of the version'),
            Column::text('prev_hash', 'Hash of the previous version of the model'),
            Column::text('global_prev_hash', 'Hash of the previous version in the global chain'),
            Column::numeric('anchor_id', 'Anchor that attests the version'),
            Column::text('snapshot', 'Snapshot as hashed (canonical JSON, personal data encrypted)'),
        ];

        if ($reveal) {
            $versionColumns[] = Column::text('snapshot_revealed', 'Snapshot with personal data decrypted (null where the key was shredded)');
        }

        return [
            'versions' => new Table('versions.csv', 'versions', 'Recorded versions of all models', $versionColumns),
            'inclusion_proofs' => new Table('inclusion_proofs.csv', 'inclusion_proofs', 'Merkle inclusion proofs of the versions in their anchors (RFC 6962)', [
                Column::numeric('sequence', 'Version (global sequence)', primaryKey: true),
                Column::numeric('anchor_id', 'Anchor'),
                Column::numeric('leaf_index', 'Position of the version in the anchored range, from 0'),
                Column::numeric('tree_size', 'Number of versions in the anchored range'),
                Column::text('audit_path', 'Sibling hashes from the leaf up, separated by spaces'),
            ]),
            'anchors' => new Table('anchors.csv', 'anchors', 'Anchors: statements about ranges of the global chain', [
                Column::numeric('id', 'Anchor', primaryKey: true),
                Column::numeric('anchor_format', 'Anchor format'),
                Column::numeric('from_sequence', 'First version of the range'),
                Column::numeric('to_sequence', 'Last version of the range'),
                Column::text('merkle_root', 'Merkle root of the version hashes of the range'),
                Column::text('prev_digest', 'Digest of the previous anchor'),
                Column::text('digest', 'SHA-256 digest of the statement'),
                Column::date('created_date', 'Date created (UTC)'),
                Column::time('created_time', 'Time created (UTC)'),
                Column::text('created_at', 'Time created, ISO 8601 (UTC)'),
                Column::text('statement_file', 'File with the canonical statement'),
            ]),
            'anchor_proofs' => new Table('anchor_proofs.csv', 'anchor_proofs', 'Proofs of the anchors, per driver; the latest per driver counts', [
                Column::numeric('id', 'Proof', primaryKey: true),
                Column::numeric('anchor_id', 'Anchor'),
                Column::text('driver', 'Anchor driver'),
                Column::date('created_date', 'Date stored (UTC)'),
                Column::time('created_time', 'Time stored (UTC)'),
                Column::text('created_at', 'Time stored, ISO 8601 (UTC)'),
                Column::text('file', 'Proof file, checkable with standard tools'),
            ]),
            'files' => new Table('files.csv', 'files', 'Stored files, content-addressed', [
                Column::text('sha256', 'SHA-256 hash of the file as stored (encrypted, if it has a key)', primaryKey: true),
                Column::text('disk', 'Storage disk'),
                Column::text('path', 'Path on the disk'),
                Column::numeric('size', 'Size of the file as stored, in bytes'),
                Column::text('mime', 'MIME type'),
                Column::text('key_id', 'Key of the data subject the file is encrypted with; empty if it is not encrypted'),
                Column::date('created_date', 'Date stored (UTC)'),
                Column::time('created_time', 'Time stored (UTC)'),
            ]),
        ];
    }
}
