<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Exceptions\TableCreateFailedException;
use Phoenix\Database\Exceptions\TableDropFailedException;
use Phoenix\Database\Exceptions\TableNotFoundException;

interface TableCreateStrategy {
    /**
     * Create a table based on the provided table definition.
     *
     * @param Table $table The table definition.
     *
     * @return void
     * @throws TableCreateFailedException
     */
    public function create(Table $table): void;
}
