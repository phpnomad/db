<?php

namespace Phoenix\Database\Traits;

use Phoenix\Database\Exceptions\TableCreateFailedException;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Database\Interfaces\TableCreateStrategy;

trait CanCreateTables
{
    protected TableCreateStrategy $tableCreateStrategy;

    /**
     * @param Table[] $tables
     * @return void
     * @throws TableCreateFailedException
     */
    protected function createTables(array $tables)
    {
        foreach($tables as $table){
            $this->tableCreateStrategy->create($table);
        }
    }
}