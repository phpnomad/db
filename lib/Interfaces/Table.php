<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Factories\Column;
use Phoenix\Database\Factories\Index;

interface Table
{
    /**
     * Gets the name of this table.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Gets the alias for this table.
     *
     * @return string
     */
    public function getAlias(): string;

    /**
     * Gets the TTL value that should be used when caching data in this table.
     *
     * @return int
     */
    public function getCacheTtl(): int;

    /**
     * Gets the current version of the table.
     *
     * @return string
     */
    public function getTableVersion(): string;

    /**
     * Gets the list of columns in the table.
     *
     * @return Column[]
     */
    public function getColumns(): array;

    /**
     * Gets the list of columns in the table.
     *
     * @return Index[]
     */
    public function getIndices(): array;

    /**
     * Get the charset of the table.
     *
     * @return ?string
     */
    public function getCharset(): ?string;

    /**
     * Get the collation of the table.
     *
     * @return ?string
     */
    public function getCollation(): ?string;

    /**
     * Gets the list of field names that are required to identify this model.
     *
     * @return non-empty-array<string>
     */
    public static function getFieldsForIdentity(): array;
}