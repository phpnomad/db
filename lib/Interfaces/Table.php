<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Exceptions\DatabaseErrorException;

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
     * Installs the table.
     *
     * @return void
     * @throws DatabaseErrorException
     */
    public function install(): void;
}