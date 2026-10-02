<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;

/**
 * A data subject of its own: its personal attributes use its own key.
 */
class Customer extends Model
{
    use HasIntegrity;
    use SoftDeletes;

    protected string $integrityDeletes = 'record';

    /** @var list<string> */
    protected array $integrityPersonal = ['name', 'email'];

    /** @var array<string, mixed> */
    protected array $integrityAnonymized = ['name' => 'deleted'];

    protected $guarded = [];
}
