<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;

/**
 * Covers every cast type the snapshot builder normalizes.
 */
class Invoice extends Model
{
    use HasIntegrity;

    protected string $integrityMode = 'versioned';

    protected string $integrityDeletes = 'record';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'rate' => 'float',
            'paid' => 'boolean',
            'quantity' => 'integer',
            'issued_at' => 'datetime',
            'due_on' => 'date',
            'meta' => 'array',
            'status' => InvoiceStatus::class,
            'priority' => InvoicePriority::class,
            'secret' => 'encrypted',
        ];
    }
}
