<?php

namespace Phoenix\Database\Traits;

namespace Phoenix\Database\Traits;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Interfaces\Table;

trait CanInstallTables
{
    /**
     * @var Table[]
     */
    protected $tables = [];

    /**
     * @return void
     * @throws DatabaseErrorException
     */
    protected function installTables(): void
    {
        foreach ($this->tables as $table) {
            $table->install();
        }
    }
}