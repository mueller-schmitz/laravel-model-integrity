<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | Connection used for the integrity tables. Null uses the default
    | connection. Versions are written in the same transaction as the model,
    | so this should be the connection your integrity models live on.
    |
    */

    'connection' => env('MODEL_INTEGRITY_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    */

    'tables' => [
        'versions' => 'integrity_versions',
        'heads' => 'integrity_heads',
        'files' => 'integrity_files',
        'anchors' => 'integrity_anchors',
        'anchor_proofs' => 'integrity_anchor_proofs',
        'subject_keys' => 'integrity_subject_keys',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stored files
    |--------------------------------------------------------------------------
    |
    | Files are stored content-addressed under {path}/{aa}/{bb}/{sha256} and
    | never overwritten or deleted. Use a private disk and restrict write
    | access to it: changes on the disk are detected, not prevented.
    |
    */

    'files' => [
        'disk' => env('MODEL_INTEGRITY_FILES_DISK', 'local'),
        'path' => 'integrity-files',
    ],

    /*
    |--------------------------------------------------------------------------
    | Actor
    |--------------------------------------------------------------------------
    |
    | Guards asked for the authenticated user, in order, when no actor was set
    | with ModelIntegrity::actingAs(). Null asks the default guard only.
    |
    */

    'actor' => [
        'guards' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anchors
    |--------------------------------------------------------------------------
    |
    | `php artisan model-integrity:anchor` attests new versions outside the
    | database with each of the listed drivers. An anchor only helps if the
    | database cannot change where it is kept: use a disk on other storage,
    | ideally write-once (e.g. a bucket with object lock), with credentials
    | separate from the database.
    |
    */

    'anchors' => [
        'drivers' => ['disk'],

        'disk' => [
            'disk' => env('MODEL_INTEGRITY_ANCHOR_DISK', 'local'),
            'path' => 'integrity-anchors',
        ],

        // Free, no account: public calendars commit to Bitcoin within hours.
        // Bitcoin attestations are checked against block headers from an
        // Esplora API; point esplora_url to your own node to rely on no one.
        'opentimestamps' => [
            'calendars' => [
                'https://alice.btc.calendar.opentimestamps.org',
                'https://bob.btc.calendar.opentimestamps.org',
                'https://finney.calendar.eternitywall.com',
                'https://btc.calendar.catallaxy.com',
            ],
            'min_calendars' => 2,
            'timeout' => 10,
            'esplora_url' => env('MODEL_INTEGRITY_ESPLORA_URL', 'https://blockstream.info/api'),
        ],

        // An RFC 3161 time-stamp authority, e.g. https://freetsa.org/tsr (free)
        // or a qualified trust service provider. ca_file is the PEM file with
        // the CA certificates the TSA certificate must lead to. Needs ext-openssl.
        'rfc3161' => [
            'url' => env('MODEL_INTEGRITY_TSA_URL'),
            'ca_file' => env('MODEL_INTEGRITY_TSA_CA_FILE'),
            'intermediates_file' => null,
            'policy' => null,
            'timeout' => 10,
            'headers' => [],
        ],

        // A proof attested (or still pending) later than this after the
        // versions it attests were recorded proves nothing about their time.
        'max_delay_hours' => 72,

        // When anchoring was enabled (e.g. "2026-10-01"). Versions recorded
        // before count from this date: set it when upgrading an application
        // that already has versions.
        'since' => env('MODEL_INTEGRITY_ANCHORS_SINCE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Append-only triggers
    |--------------------------------------------------------------------------
    |
    | The migrations install database triggers that reject UPDATE and DELETE on
    | recorded versions (MySQL, MariaDB, PostgreSQL, SQLite). Disable only if
    | the database user may not create triggers, and restrict privileges with
    | the statements from `php artisan model-integrity:grants` instead.
    |
    */

    'append_only_triggers' => filter_var(env('MODEL_INTEGRITY_APPEND_ONLY_TRIGGERS', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Model defaults
    |--------------------------------------------------------------------------
    |
    | Used when a model does not define its own $integrityMode,
    | $integrityDeletes or $integrityExcept.
    |
    | mode:    'versioned' records every change, 'immutable' forbids changes
    | deletes: 'forbid' throws on delete, 'record' records a deleted version
    | except:  attributes excluded from snapshots
    |
    */

    'defaults' => [
        'mode' => 'versioned',
        'deletes' => 'forbid',
        'except' => ['updated_at'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Hash format
    |--------------------------------------------------------------------------
    |
    | Format version used for new versions. Every version stores the format it
    | was hashed with, and a format is never changed once released. Only
    | change this when upgrading to a newer format.
    |
    */

    'hash_format' => 1,

];
