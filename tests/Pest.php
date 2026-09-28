<?php

declare(strict_types=1);

use MuellerSchmitz\ModelIntegrity\Tests\ConcurrencyTestCase;
use MuellerSchmitz\ModelIntegrity\Tests\EnforcementTestCase;
use MuellerSchmitz\ModelIntegrity\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(EnforcementTestCase::class)->in('Enforcement');
pest()->extend(ConcurrencyTestCase::class)->in('Concurrency');
