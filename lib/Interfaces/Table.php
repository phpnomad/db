<?php

namespace Phoenix\Database\Interfaces;
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
}