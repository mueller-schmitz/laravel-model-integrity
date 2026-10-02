<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use MuellerSchmitz\ModelIntegrity\Export\Column;
use MuellerSchmitz\ModelIntegrity\Export\CsvWriter;
use MuellerSchmitz\ModelIntegrity\Export\GdpduIndex;
use MuellerSchmitz\ModelIntegrity\Export\Table;

beforeEach(function (): void {
    $this->table = new Table('invoices.csv', 'invoices', 'Invoices', [
        Column::numeric('id', 'Internal ID', primaryKey: true),
        Column::text('customer', 'Customer name'),
        Column::numeric('total', 'Gross total', accuracy: 2),
        Column::date('issued_on', 'Date of issue'),
        Column::time('issued_time', 'Time of issue (UTC)'),
    ]);
    $this->path = tempnam(sys_get_temp_dir(), 'mi-csv');
});

afterEach(fn () => @unlink($this->path));

it('writes UTF-8 CSV with a header, semicolons, CRLF and quoted text', function (): void {
    $writer = CsvWriter::open($this->path, $this->table);
    $writer->write([1, 'Müller & Söhne KG', '1190.00', CarbonImmutable::parse('2026-03-15 14:05:09'), CarbonImmutable::parse('2026-03-15 14:05:09')]);
    $writer->write([2, "Firma \"Nord\";\r\nGmbH", '-89.5', null, null]);
    $writer->close();

    expect(file_get_contents($this->path))->toBe(
        "\"id\";\"customer\";\"total\";\"issued_on\";\"issued_time\"\r\n"
        ."1;\"Müller & Söhne KG\";1190,00;15.03.2026;\"14:05:09\"\r\n"
        ."2;\"Firma \"\"Nord\"\"; GmbH\";-89,50;;\r\n",
    );
});

it('rejects rows that do not match the columns', function (): void {
    CsvWriter::open($this->path, $this->table)->write([1, 'x']);
})->throws(InvalidArgumentException::class);

it('describes the tables in the order the DTD requires', function (): void {
    $xml = (new GdpduIndex('Example GmbH', 'Example City', 'Export'))->build([$this->table]);

    expect($xml)->toStartWith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!DOCTYPE DataSet SYSTEM \"gdpdu-01-03-2019.dtd\">")
        ->and($xml)->toContain('<RecordDelimiter>&#13;&#10;</RecordDelimiter>')
        ->toContain('<Numeric><Accuracy>2</Accuracy></Numeric>')
        ->toContain('<Date><Format>DD.MM.YYYY</Format></Date>')
        ->toContain('<Map><From>HH:MM:SS</From><To>HH:MM:SS</To></Map>')
        ->toContain('<Range><From>2</From></Range>')
        ->and(strpos($xml, '<URL>'))->toBeLessThan(strpos($xml, '<UTF8/>'))
        ->and(strpos($xml, '<UTF8/>'))->toBeLessThan(strpos($xml, '<DecimalSymbol>'))
        ->and(strpos($xml, '<DigitGroupingSymbol>'))->toBeLessThan(strpos($xml, '<Range>'))
        ->and(strpos($xml, '<VariablePrimaryKey>'))->toBeLessThan(strpos($xml, '<VariableColumn>'));
});

it('escapes XML special characters in names and descriptions', function (): void {
    $xml = (new GdpduIndex('Müller & <Söhne>', 'X', 'Y'))->build([$this->table]);

    expect($xml)->toContain('<Name>Müller &amp; &lt;Söhne&gt;</Name>');
});

it('is valid against the GDPdU DTD', function (): void {
    $dtd = (string) getenv('MI_GDPDU_DTD');
    $directory = sys_get_temp_dir().'/mi-gdpdu-'.bin2hex(random_bytes(4));
    mkdir($directory);
    copy($dtd, $directory.'/gdpdu-01-03-2019.dtd');
    file_put_contents($directory.'/index.xml', (new GdpduIndex('Example GmbH', 'Example City', 'Export'))->build([$this->table]));

    $document = new DOMDocument;
    $document->load($directory.'/index.xml', LIBXML_DTDLOAD);
    libxml_use_internal_errors(true);
    $valid = $document->validate();
    // The published DTD itself is flagged as not deterministic by libxml; that is no error of the file.
    $errors = array_values(array_filter(array_map(fn (LibXMLError $error): string => trim($error->message), libxml_get_errors()), fn (string $message): bool => ! str_contains($message, 'is not determinist')));
    libxml_clear_errors();
    array_map(unlink(...), glob($directory.'/*') ?: []);
    rmdir($directory);

    expect($valid)->toBeTrue()->and($errors)->toBe([]);
})->skip(fn () => getenv('MI_GDPDU_DTD') === false, 'Set MI_GDPDU_DTD to the path of gdpdu-01-03-2019.dtd to validate against it.');
