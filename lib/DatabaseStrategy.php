<?php

namespace Phoenix\Database;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\QueryBuilderException;
use Phoenix\Database\Exceptions\RecordNotFoundException;

interface DatabaseStrategy
{
    /**
     * Retrieve a record by its primary key.
     *
     * @param string $table
     * @param mixed $id
     * @return array<string,mixed>
     * @throws DatabaseErrorException
     * @throws RecordNotFoundException
     */
    public function find(string $table, $id): array;

    /**
     * Retrieve all records from a table.
     *
     * @param string $table
     * @return array<string,mixed>[]
     * @throws DatabaseErrorException
     */
    public function all(string $table): array;

    /**
     * Query the database with conditions.
     *
     * @param string $table
     * @param array{column: string, operator: string, value: mixed}[] $conditions
     * @return array<string,mixed>[]
     * @throws DatabaseErrorException
     */
    public function where(string $table, array $conditions): array;

    /**
     * Insert a new record and return the instance.
     *
     * @param string $table
     * @param array<string, mixed> $attributes
     * @return int Inserted record ID.
     * @throws DatabaseErrorException
     */
    public function create(string $table, array $attributes): int;

    /**
     * Update a record in the database.
     *
     * @param string $table
     * @param mixed $id
     * @param array<string, mixed> $attributes
     * @return void
     * @throws RecordNotFoundException
     * @throws DatabaseErrorException
     */
    public function update(string $table, $id, array $attributes): void;

    /**
     * Delete a record from the database.
     *
     * @param string $table
     * @param mixed $id
     * @return void
     * @throws DatabaseErrorException
     */
    public function delete(string $table, $id): void;

    /**
     * Count records in the table with optional conditions.
     *
     * @param string $table
     * @param array{column: string, operator: string, value: mixed}[] $conditions
     * @return int
     * @throws DatabaseErrorException
     */
    public function count(string $table, array $conditions = []): int;

    /**
     * Fetches data using a hard-coded query.
     *
     *
     * @param QueryBuilder $query
     * @throws QueryBuilderException
     * @throws DatabaseErrorException
     * @return array
     */
    public function query(QueryBuilder $query): array;
}
