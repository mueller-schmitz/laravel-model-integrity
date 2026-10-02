<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

/**
 * A column of an exported table and its GDPdU type.
 */
final readonly class Column
{
    public const string NUMERIC = 'numeric';

    public const string TEXT = 'text';

    public const string DATE = 'date';

    /** GDPdU has no time type; times are text marked as time by a map (standard 1.6). */
    public const string TIME = 'time';

    private function __construct(
        public string $name,
        public string $type,
        public string $description,
        public int $accuracy = 0,
        public bool $primaryKey = false,
    ) {}

    public static function numeric(string $name, string $description, int $accuracy = 0, bool $primaryKey = false): self
    {
        return new self($name, self::NUMERIC, $description, $accuracy, $primaryKey);
    }

    public static function text(string $name, string $description, bool $primaryKey = false): self
    {
        return new self($name, self::TEXT, $description, 0, $primaryKey);
    }

    public static function date(string $name, string $description): self
    {
        return new self($name, self::DATE, $description);
    }

    public static function time(string $name, string $description): self
    {
        return new self($name, self::TIME, $description);
    }
}
