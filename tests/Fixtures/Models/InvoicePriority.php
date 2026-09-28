<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Tests\Fixtures\Models;

enum InvoicePriority: int
{
    case Low = 1;
    case High = 2;
}
