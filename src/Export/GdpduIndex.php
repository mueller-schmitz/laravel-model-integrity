<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

use InvalidArgumentException;

/**
 * Builds the index.xml of the GDPdU description standard (DTD
 * gdpdu-01-03-2019.dtd), which audit software of the German tax
 * authorities (IDEA) reads to import the CSV files. Elements follow the
 * order the DTD requires.
 */
final class GdpduIndex
{
    public const string DTD = 'gdpdu-01-03-2019.dtd';

    public function __construct(
        private readonly string $supplierName,
        private readonly string $supplierLocation,
        private readonly string $comment,
    ) {}

    /**
     * @param  list<Table>  $tables
     */
    public function build(array $tables): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE DataSet SYSTEM \"".self::DTD."\">\n<DataSet>\n"
            ."  <Version>1.0</Version>\n"
            ."  <DataSupplier>\n"
            .'    <Name>'.$this->escape($this->supplierName)."</Name>\n"
            .'    <Location>'.$this->escape($this->supplierLocation)."</Location>\n"
            .'    <Comment>'.$this->escape($this->comment)."</Comment>\n"
            ."  </DataSupplier>\n"
            ."  <Media>\n"
            ."    <Name>model-integrity export</Name>\n";

        foreach ($tables as $table) {
            $xml .= $this->table($table);
        }

        return $xml."  </Media>\n</DataSet>\n";
    }

    private function table(Table $table): string
    {
        $xml = "    <Table>\n"
            .'      <URL>'.$this->escape($table->file)."</URL>\n"
            .'      <Name>'.$this->escape($table->name)."</Name>\n"
            .'      <Description>'.$this->escape($table->description)."</Description>\n"
            ."      <UTF8/>\n"
            ."      <DecimalSymbol>,</DecimalSymbol>\n"
            ."      <DigitGroupingSymbol>.</DigitGroupingSymbol>\n"
            // The first record is the header line.
            ."      <Range><From>2</From></Range>\n"
            ."      <VariableLength>\n"
            ."        <ColumnDelimiter>;</ColumnDelimiter>\n"
            ."        <RecordDelimiter>&#13;&#10;</RecordDelimiter>\n"
            ."        <TextEncapsulator>\"</TextEncapsulator>\n";

        // Primary keys come first in the DTD; the columns of the file must keep their order.
        $columns = $table->columns;
        $keys = array_filter($columns, fn (Column $column): bool => $column->primaryKey);

        if ($keys !== [] && array_keys($keys) !== range(0, count($keys) - 1)) {
            throw new InvalidArgumentException("The primary key columns of [{$table->file}] must come first.");
        }

        foreach ($columns as $column) {
            $xml .= $this->column($column);
        }

        return $xml."      </VariableLength>\n    </Table>\n";
    }

    private function column(Column $column): string
    {
        $element = $column->primaryKey ? 'VariablePrimaryKey' : 'VariableColumn';

        $type = match ($column->type) {
            Column::NUMERIC => $column->accuracy > 0 ? "<Numeric><Accuracy>{$column->accuracy}</Accuracy></Numeric>" : '<Numeric/>',
            Column::DATE => '<Date><Format>DD.MM.YYYY</Format></Date>',
            Column::TIME => '<AlphaNumeric/><Map><From>HH:MM:SS</From><To>HH:MM:SS</To></Map>',
            default => '<AlphaNumeric/>',
        };

        return "        <{$element}><Name>".$this->escape($column->name).'</Name><Description>'.$this->escape($column->description)."</Description>{$type}</{$element}>\n";
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
