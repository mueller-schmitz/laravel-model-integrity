<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;

class Contract extends Model
{
    use HasIntegrity;
    use SoftDeletes;

    protected string $integrityDeletes = 'record';

    protected $guarded = [];
}
