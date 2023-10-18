<?php

namespace Phoenix\Database\Traits;

namespace Phoenix\Database\Traits;

use Phoenix\Database\Interfaces\Table;

trait WithUseTable
{
    /**
     * @var Table
     */
    protected Table $table;

    /** $inheritDoc */
    public function useTable(Table $table)
    {
        $this->table = $table;

        return $this;
    }

}