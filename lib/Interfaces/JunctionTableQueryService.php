<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Exceptions\DatabaseErrorException;

interface JunctionTableQueryService
{
    /**
     * Fetch a list of records for the specified table.
     *
     * @param string $tableName The name of the table from which you want to receive the IDs.
     * @param int $id The ID associated with the opposite table as the table specified in the previous argument.
     * @param int $limit The limit of records to get.
     * @param int $offset The record offset.
     * @return int[] IDs associated with the table specified in $tableName.
     * @throws DatabaseErrorException
     */
    public function getIdsFromTable(string $tableName, int $id, int $limit, int $offset): array;
}