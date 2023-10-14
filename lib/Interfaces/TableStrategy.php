<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Exceptions\TableCreateFailedException;
use Phoenix\Database\Exceptions\TableDropFailedException;
use Phoenix\Database\Exceptions\TableNotFoundException;

interface TableStrategy {
    /**
     * Create a table based on the provided table definition.
     *
     * @param Table $table The table definition.
     *
     * @return void
     * @throws TableCreateFailedException
     */
    public function create(Table $table): void;

    /**
     * Check if a table exists in the database.
     *
     * @param string $tableName Name of the table to check.
     *
     * @return bool True if the table exists, false otherwise.
     */
    public function exists(string $tableName): bool;

    /**
     * Drop or delete a table from the database.
     *
     * @param string $tableName Name of the table to drop.
     *
     * @return void
     * @throws TableDropFailedException
     * @throws TableNotFoundException
     */
    public function drop(string $tableName): void;
}
