<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use MuellerSchmitz\ModelIntegrity\Concerns\HasIntegrity;

class Document extends Model
{
    use HasIntegrity;

    protected string $integrityMode = 'immutable';

    protected string $integrityDeletes = 'forbid';

    protected $guarded = [];
}
