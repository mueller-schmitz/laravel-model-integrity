<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;

/**
 * Personal data of another data subject: the customer.
 */
class Order extends Model
{
    use HasIntegrity;

    /** @var list<string> */
    protected array $integrityPersonal = ['shipping_name', 'shipping_address'];

    /** @var array<string, mixed> */
    protected array $integrityAnonymized = ['shipping_name' => ''];

    protected $guarded = [];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function integritySubject(): ?Model
    {
        return $this->customer;
    }

    protected function casts(): array
    {
        return [
            'shipping_address' => 'array',
            'total' => 'decimal:2',
        ];
    }
}
