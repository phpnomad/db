<?php

namespace Phoenix\Database\Interfaces;

interface HasUsableTable
{
    /**
     * Specifies the table that should be used.
     *
     * @param Table $table
     * @return $this
     */
    public function useTable(Table $table);
}