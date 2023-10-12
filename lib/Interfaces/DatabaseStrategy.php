<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\RecordNotFoundException;

interface DatabaseStrategy
{

    /**
     * Gets the database global prefix, if it has one.
     *
     * @return ?string
     * @throws DatabaseErrorException
     */
    public function prefix(): ?string;

    /**
     * Retrieve a record by its primary key.
     *
     * @param Table $table
     * @param mixed $id
     * @return array<string,mixed>
     * @throws DatabaseErrorException
     * @throws RecordNotFoundException
     */
    public function find(Table $table, $id): array;

    /**
     * Retrieve all records from a table.
     *
     * @param Table $table
     * @return array<string,mixed>[]
     * @throws DatabaseErrorException
     */
    public function all(Table $table): array;

    /**
     * Query the database with conditions.
     *
     * @param Table $table
     * @param array{column: string, operator: string, value: mixed}[] $conditions
     * @param positive-int|null $limit
     * @param positive-int|null $offset
     * @return array<string,mixed>[]
     * @throws DatabaseErrorException
     */
    public function where(Table $table, array $conditions, ?int $limit = null, ?int $offset = null): array;

    /**
     * Finds the first available record that has the specified value in the specified column.
     *
     * @param Table $table
     * @param string $column
     * @param $value
     * @return array<string,mixed>
     * @throws DatabaseErrorException
     * @throws RecordNotFoundException
     */
    public function findBy(Table $table, string $column, $value): array;

    /**
     * Insert a new record and return the instance.
     *
     * @param Table $table
     * @param array<string, mixed> $attributes
     * @return int Inserted record ID.
     * @throws DatabaseErrorException
     */
    public function create(Table $table, array $attributes): int;

    /**
     * Update a record in the database.
     *
     * @param Table $table
     * @param mixed $id
     * @param array<string, mixed> $attributes
     * @return void
     * @throws RecordNotFoundException
     * @throws DatabaseErrorException
     */
    public function update(Table $table, $id, array $attributes): void;

    /**
     * Delete a record from the database.
     *
     * @param Table $table
     * @param mixed $id
     * @return void
     * @throws DatabaseErrorException
     */
    public function delete(Table $table, $id): void;

    /**
     * Count records in the table with optional conditions.
     *
     * @param Table $table
     * @param array{column: string, operator: string, value: mixed}[] $conditions
     * @return int
     * @throws DatabaseErrorException
     */
    public function count(Table $table, array $conditions = []): int;

    /**
     * Query the database with conditions.
     *
     * @param Table $table
     * @param array{column: string, operator: string, value: mixed}[] $conditions
     * @param positive-int|null $limit
     * @param positive-int|null $offset
     * @return int[]
     * @throws DatabaseErrorException
     */
    public function findIds(Table $table, array $conditions, ?int $limit = null, ?int $offset = null): array;

    /**
     * Run an arbitrary query.
     *
     * @param QueryBuilder $builder
     * @return array<string,mixed>[]
     * @throws DatabaseErrorException
     */
    public function query(QueryBuilder $builder): array;
}
