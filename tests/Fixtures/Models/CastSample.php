<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Model;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Support\Cents;
use MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Support\TagCollection;

/**
 * Cast types not covered by Invoice.
 */
class CastSample extends Model
{
    use HasIntegrity;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'happened_at' => 'datetime:Y-m-d H:i',
            'stamp' => 'timestamp',
            'frozen_at' => 'immutable_datetime',
            'born_on' => 'immutable_date',
            'payload' => 'object',
            'items' => 'collection',
            'tags' => AsCollection::using(TagCollection::class),
            'statuses' => AsEnumCollection::of(InvoiceStatus::class),
            'label' => 'string',
            'amount' => Cents::class,
            'password' => 'hashed',
        ];
    }
}
