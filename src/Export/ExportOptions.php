<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

use Carbon\CarbonImmutable;

/**
 * What an auditor export contains.
 */
final readonly class ExportOptions
{
    public function __construct(
        /** versions recorded from this moment on (UTC, inclusive) */
        public ?CarbonImmutable $from = null,
        /** versions recorded up to this moment (UTC, inclusive) */
        public ?CarbonImmutable $to = null,
        /** only versions of this morph type */
        public ?string $type = null,
        /** add the snapshots with decrypted personal data */
        public bool $reveal = true,
        /** also hash the content of every stored file */
        public bool $files = false,
        /** download the GDPdU DTD if no path is configured */
        public bool $fetchDtd = false,
        /** write the export without the GDPdU DTD */
        public bool $withoutDtd = false,
    ) {}
}
