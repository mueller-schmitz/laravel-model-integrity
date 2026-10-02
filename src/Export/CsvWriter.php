<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

use DateTimeInterface;
use InvalidArgumentException;
use RuntimeException;

/**
 * Writes a table as the GDPdU description declares it: UTF-8, ';' between
 * fields, CRLF between records, a header line, text in double quotes with
 * quotes doubled, ',' as decimal symbol, empty fields for null.
 *
 * GDPdU does not define line breaks inside fields; they are replaced by a
 * space. Exact values are exported separately (versions.jsonl).
 */
final class CsvWriter
{
    /**
     * @param  resource  $handle
     */
    private function __construct(
        private $handle,
        private readonly Table $table,
    ) {}

    public static function open(string $path, Table $table): self
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Cannot write [{$path}].");
        }

        $writer = new self($handle, $table);
        $writer->line(array_map(fn (Column $column): string => self::quote($column->name), $table->columns));

        return $writer;
    }

    /**
     * @param  list<mixed>  $values  in column order
     */
    public function write(array $values): void
    {
        if (count($values) !== count($this->table->columns)) {
            throw new InvalidArgumentException("A row of [{$this->table->file}] needs ".count($this->table->columns).' values, '.count($values).' given.');
        }

        $this->line(array_map(fn (Column $column, mixed $value): string => $this->field($column, $value), $this->table->columns, $values));
    }

    public function close(): void
    {
        fclose($this->handle);
    }

    /**
     * @param  list<string>  $fields
     */
    private function line(array $fields): void
    {
        fwrite($this->handle, implode(';', $fields)."\r\n");
    }

    private function field(Column $column, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($column->type) {
            Column::NUMERIC => $this->number($column, $value),
            Column::DATE => $this->dateTime($value)->format('d.m.Y'),
            Column::TIME => self::quote($this->dateTime($value)->format('H:i:s')),
            default => self::quote($this->text($value)),
        };
    }

    /**
     * Formats a number exactly: decimals are strings, never floats.
     */
    private function number(Column $column, mixed $value): string
    {
        $string = is_int($value) ? (string) $value : (is_string($value) ? $value : null);

        if ($string === null || preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $string, $match) !== 1) {
            throw new InvalidArgumentException("[{$column->name}] needs an integer or a decimal string.");
        }

        $fraction = $match[3] ?? '';

        if (strlen($fraction) > $column->accuracy) {
            throw new InvalidArgumentException("[{$column->name}] has more than {$column->accuracy} decimals: {$string}.");
        }

        return $match[1].$match[2].($column->accuracy > 0 ? ','.str_pad($fraction, $column->accuracy, '0') : '');
    }

    private function dateTime(mixed $value): DateTimeInterface
    {
        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('Date and time columns need date objects.');
        }

        return $value;
    }

    private function text(mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new InvalidArgumentException('Text columns need scalar values.');
        }

        return str_replace(["\r\n", "\r", "\n"], ' ', (string) $value);
    }

    private static function quote(string $text): string
    {
        return '"'.str_replace('"', '""', $text).'"';
    }
}
