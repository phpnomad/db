<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Exceptions\TableCreateFailedException;
use Phoenix\Database\Exceptions\TableDropFailedException;
use Phoenix\Database\Exceptions\TableNotFoundException;

interface TableDeleteStrategy {
    /**
     * Drop or delete a table from the database.
     *
     * @param string $tableName Name of the table to drop.
     *
     * @return void
     * @throws TableDropFailedException
     */
    public function delete(string $tableName): void;
}
