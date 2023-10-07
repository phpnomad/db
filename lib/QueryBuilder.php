<?php

namespace Phoenix\Database;

use Phoenix\Database\Exceptions\QueryBuilderException;

interface QueryBuilder
{
    /**
     * Set fields to select.
     *
     * @param array $fields - List of fields to select. Associative arrays expect the array key to be the alias.
     * @return $this
     */
    public function select(array $fields);

    /**
     * Appends to the from clause.
     *
     * @param string $table
     * @param ?string $alias
     * @return $this
     */
    public function from(string $table, ?string $alias = null);

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
     * @param string $type The type of join (e.g., INNER, LEFT, RIGHT).
     * @param string $table The table to join.
     * @param string $column The column to join by.
     * @param string $onColumn The joined column to join on.
     * @return $this
     */
    public function join(string $type, string $table, string $column, string $onColumn);

    /**
     * Adds, or overrides the GROUP BY clause.
     *
     * @param string|array $columns The columns to group by
     * @return $this
     */
    public function groupBy($columns);

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
     * Builds the SQL query.
     *
     * @throws QueryBuilderException
     * @return string
     */
    public function build(): string;
}
