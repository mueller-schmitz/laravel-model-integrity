<?php

declare(strict_types=1);

namespace MuellerSchmitz\ModelIntegrity\Export;

/**
 * An exported CSV file and its columns, in file order.
 */
final readonly class Table
{
    /**
     * @param  list<Column>  $columns
     */
    public function __construct(
        public string $file,
        public string $name,
        public string $description,
        public array $columns,
    ) {}
}
