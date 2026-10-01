<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use InvalidArgumentException;

/**
 * An OpenTimestamps proof that cannot be read or evaluated.
 */
class InvalidTimestampException extends InvalidArgumentException {}
