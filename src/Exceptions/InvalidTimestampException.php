<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Exceptions;

use InvalidArgumentException;

/**
 * An OpenTimestamps proof or RFC 3161 time-stamp that cannot be read or is not valid.
 */
class InvalidTimestampException extends InvalidArgumentException {}
