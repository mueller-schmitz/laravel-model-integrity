<?php

declare(strict_types=1);

arch('php preset')->preset()->php();

arch('no debugging calls')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

arch('strict types everywhere')
    ->expect('MuellerSchmitz\ModelIntegrity')
    ->toUseStrictTypes();
