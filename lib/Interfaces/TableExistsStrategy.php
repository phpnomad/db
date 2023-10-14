<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Exceptions\TableCreateFailedException;
use Phoenix\Database\Exceptions\TableDropFailedException;
use Phoenix\Database\Exceptions\TableNotFoundException;

interface TableExistsStrategy
{
    /**
     * Check if a table exists in the database.
     *
     * @param string $tableName Name of the table to check.
     *
     * @return bool True if the table exists, false otherwise.
     */
    public function exists(string $tableName): bool;
}
