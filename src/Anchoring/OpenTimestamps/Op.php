<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Anchoring\OpenTimestamps;

use MuellerSchmitz\ModelIntegrity\Exceptions\InvalidTimestampException;

/**
 * An operation of an OpenTimestamps proof: it turns the message of one node
 * into the message of the next. Unary operations have no argument; append
 * and prepend take one.
 */
final readonly class Op
{
    public const int SHA1 = 0x02;

    public const int RIPEMD160 = 0x03;

    public const int SHA256 = 0x08;

    public const int APPEND = 0xF0;

    public const int PREPEND = 0xF1;

    public const int REVERSE = 0xF2;

    public const int HEXLIFY = 0xF3;

    /** Longest message any operation may produce. */
    public const int MAX_MESSAGE_LENGTH = 4096;

    private function __construct(
        public int $tag,
        public ?string $argument = null,
    ) {}

    public static function fromTag(int $tag, ?string $argument = null): self
    {
        $binary = $tag === self::APPEND || $tag === self::PREPEND;

        if (! in_array($tag, [self::SHA1, self::RIPEMD160, self::SHA256, self::APPEND, self::PREPEND, self::REVERSE, self::HEXLIFY], true)) {
            throw new InvalidTimestampException(sprintf('Unsupported operation [0x%02x].', $tag));
        }

        if ($binary !== ($argument !== null)) {
            throw new InvalidTimestampException(sprintf('Operation [0x%02x] %s an argument.', $tag, $binary ? 'requires' : 'takes no'));
        }

        if ($argument !== null && ($argument === '' || strlen($argument) > self::MAX_MESSAGE_LENGTH)) {
            throw new InvalidTimestampException('The argument of an operation must have 1 to '.self::MAX_MESSAGE_LENGTH.' bytes.');
        }

        return new self($tag, $argument);
    }

    public static function sha256(): self
    {
        return new self(self::SHA256);
    }

    public static function sha1(): self
    {
        return new self(self::SHA1);
    }

    public static function ripemd160(): self
    {
        return new self(self::RIPEMD160);
    }

    public static function append(string $suffix): self
    {
        return self::fromTag(self::APPEND, $suffix);
    }

    public static function prepend(string $prefix): self
    {
        return self::fromTag(self::PREPEND, $prefix);
    }

    public static function reverse(): self
    {
        return new self(self::REVERSE);
    }

    public static function hexlify(): self
    {
        return new self(self::HEXLIFY);
    }

    public function apply(string $message): string
    {
        $result = match ($this->tag) {
            self::SHA1 => hash('sha1', $message, true),
            self::RIPEMD160 => hash('ripemd160', $message, true),
            self::SHA256 => hash('sha256', $message, true),
            self::APPEND => $message.$this->argument,
            self::PREPEND => $this->argument.$message,
            self::REVERSE => strrev($message),
            self::HEXLIFY => bin2hex($message),
            default => throw new InvalidTimestampException(sprintf('Unsupported operation [0x%02x].', $this->tag)),
        };

        if (strlen($result) > self::MAX_MESSAGE_LENGTH) {
            throw new InvalidTimestampException('An operation produced a message longer than '.self::MAX_MESSAGE_LENGTH.' bytes.');
        }

        return $result;
    }

    /**
     * Identifies the operation within a node, and orders the operations when
     * a node is written.
     */
    public function key(): string
    {
        return chr($this->tag).($this->argument ?? '');
    }
}
