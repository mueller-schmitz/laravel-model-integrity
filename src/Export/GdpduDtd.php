<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

use Illuminate\Http\Client\Factory;
use RuntimeException;
use ZipArchive;

/**
 * The DTD of the GDPdU description standard, which must be next to the
 * index.xml. It is published by CaseWare (formerly Audicon) without a
 * license notice and is therefore not part of this package: it is read
 * from a configured path or downloaded from the publisher, and accepted
 * only if it is the unchanged published file.
 */
class GdpduDtd
{
    /** SHA-256 of gdpdu-01-03-2019.dtd as published by CaseWare. */
    public const string SHA256 = '691051c9828ec2bbef71527c4aa77554c09ecd49e862fc6f2bc4b14507c72a0e';

    /** The published archive has a few kilobytes; larger downloads are refused. */
    private const int MAX_DOWNLOAD = 5_242_880;

    private const int MAX_DTD = 1_048_576;

    public function __construct(
        private readonly string $sha256 = self::SHA256,
    ) {}

    public function contents(bool $fetch): string
    {
        $path = config('model-integrity.export.dtd_path');

        if (is_string($path) && $path !== '') {
            $contents = is_readable($path) ? file_get_contents($path) : false;

            if ($contents === false) {
                throw new RuntimeException("Cannot read the GDPdU DTD at [{$path}].");
            }

            return $this->checked($contents, $path);
        }

        if (! $fetch) {
            throw new RuntimeException('The GDPdU DTD (gdpdu-01-03-2019.dtd) is required next to the index.xml. Download it from https://www.caseware.com/de/beschreibungsstandard and set model-integrity.export.dtd_path, or run the export with --fetch-dtd.');
        }

        return $this->checked($this->download(), 'the download');
    }

    private function download(): string
    {
        $url = config('model-integrity.export.dtd_url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('No download URL for the GDPdU DTD configured (model-integrity.export.dtd_url).');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Downloading the GDPdU DTD requires the PHP extension zip; set model-integrity.export.dtd_path instead.');
        }

        $response = app(Factory::class)->timeout(30)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Downloading the GDPdU DTD from [{$url}] failed with HTTP {$response->status()}; download it manually and set model-integrity.export.dtd_path.");
        }

        if (strlen($response->body()) > self::MAX_DOWNLOAD) {
            throw new RuntimeException("The download from [{$url}] is larger than expected; download the DTD manually.");
        }

        $zip = tempnam(sys_get_temp_dir(), 'mi-gdpdu');

        if ($zip === false || file_put_contents($zip, $response->body()) === false) {
            throw new RuntimeException('Cannot store the downloaded GDPdU archive.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($zip) !== true) {
                throw new RuntimeException("The download from [{$url}] is no ZIP archive.");
            }

            $contents = null;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);

                if (is_array($entry) && basename($entry['name']) === GdpduIndex::DTD && $entry['size'] <= self::MAX_DTD) {
                    $read = $archive->getFromIndex($index);
                    $contents = is_string($read) ? $read : null;

                    break;
                }
            }

            $archive->close();

            return $contents ?? throw new RuntimeException("The download from [{$url}] does not contain ".GdpduIndex::DTD.'.');
        } finally {
            @unlink($zip);
        }
    }

    private function checked(string $contents, string $source): string
    {
        if (! hash_equals($this->sha256, hash('sha256', $contents))) {
            throw new RuntimeException("The GDPdU DTD from {$source} is not the published file (SHA-256 differs); it must not be changed.");
        }

        return $contents;
    }
}
