<?php

namespace PHPNomad\Database\Interfaces;

use PHPNomad\Database\Exceptions\QueryBuilderException;

/**
 * Builder class to create safe SQL queries
 */
interface QueryBuilder extends HasUsableTable
{
    /**
     * Set fields to select. Uses the alias from the provided Table object
     * @param string $field - Field to select.
     * @param string ...$fields - List of additional fields to select.
     * @return $this
     */
    public function select(string $field, string ...$fields);

    /**
     * Sets the form clause to the current table.
     *
     * @return $this
     */
    public function from(Table $table);

    /**
     * Creates, or overrides the where clause with a compound statement.
     *
     * @param array $fields The field name
     * @param string $operand The operand to use in the clause
     * @param array<scalar> $valueSet The expected value, or multiple values
     * @param array<scalar>[] $valueSet Optional. Additional values for operands that accept multiple values.
     * @return $this
     */
    public function compoundWhere(array $fields, array $valueSet, array ...$valueSets);

    /**
     * Creates, or overrides the where clause.
     *
     * @param string $field The field name
     * @param string $operand The operand to use in the clause
     * @param scalar $value The expected value, or multiple values
     * @param (int|float|string)[] $values Optional. Additional values for operands that accept multiple values.
     * @return $this
     */
    public function where(string $field, string $operand, $value, ...$values);

    /**
     * appends an AND statement to the where clause
     *
     * @param string $field The field name
     * @param string $operand The operand to use in the clause
     * @param scalar $value The expected value, or multiple values
     * @param (int|float|string)[] $values Optional. Additional values for operands that accept multiple values.
     * @return $this
     */
    public function andWhere(string $field, string $operand, $value, ...$values);

    /**
     * appends a OR statement to the where clause
     *
     * @param string $field The field name
     * @param string $operand The operand to use in the clause
     * @param scalar $value The expected value, or multiple values
     * @param (int|float|string)[] $values Optional. Additional values for operands that accept multiple values.
     * @return $this
     */
    public function orWhere(string $field, string $operand, $value, ...$values);

    /**
     * Adds, or overrides the LEFT JOIN clause.
     *
     * @param Table $table The table to join.
     * @param string $column The column to join by.
     * @param string $onColumn The joined column to join on.
     * @return $this
     */
    public function leftJoin(Table $table, string $column, string $onColumn);

    /**
     * Adds, or overrides the LEFT JOIN clause.
     *
     * @param Table $table The table to join.
     * @param string $column The column to join by.
     * @param string $onColumn The joined column to join on.
     * @return $this
     */
    public function rightJoin(Table $table, string $column, string $onColumn);

    /**
     * Adds, or overrides the GROUP BY clause.
     *
     * @param string $column The column to group by.
     * @param string ...$columns Additional columns to group by
     * @return $this
     */
    public function groupBy(string $column, string ...$columns);

    /**
     * Adds a sum field to the query.
     *
     * @param string $fieldToSum The field to sum.
     * @param ?string $alias Alias for the resultant sum column. Optional.
     * @return $this
     */
    public function sum(string $fieldToSum, ?string $alias = null);

    /**
     * Adds a sum field to the query.
     * @param string $fieldToCount
     * @param ?string $alias Alias for the resultant sum column. Optional.
     * @return $this
     */
    public function count(string $fieldToCount, ?string $alias = null);

    /**
     * Adds, or replaces the LIMIT clause.
     *
     * @param positive-int $limit The limit to set
     * @return $this
     */
    public function limit(int $limit);

    /**
     * Specifies the OFFSET for the query.
     *
     * @param positive-int $offset The number of records to skip before starting retrieval
     * @return $this
     */
    public function offset(int $offset);

    /**
     * Adds, or replaces the ORDER BY clause
     *
     * @param string $field The field order by.
     * @param string $order
     * @return $this
     */
    public function orderBy(string $field, string $order);

    /**
     * Builds a secure, fully escaped SQL query.
     *
     * @return string
     * @throws QueryBuilderException
     */
    public function build(): string;

    /**
     * Reset the query to the default state.
     *
     * @return $this
     */
    public function reset();

    /**
     * Reset a specific clause to the specified state.
     *
     * @param string $clause
     * @param string ...$clauses
     * @return $this
     */
    public function resetClauses(string $clause, string ...$clauses);
}
