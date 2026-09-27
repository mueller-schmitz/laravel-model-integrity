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
    ],

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
