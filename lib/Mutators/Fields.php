<?php

namespace Phoenix\Database\Mutators;

use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Mutators\Interfaces\QueryMutator;
use Phoenix\Utils\Helpers\Arr;

/**
 * Sets the limit on the query.
 */
class Fields implements QueryMutator
{
    /**
     * @var array
     */
    protected array $fields;

    /**
     * @param string $field
     * @param string ...$fields
     */
    public function __construct(string $field, string ...$fields)
    {
        $this->fields = Arr::merge([$field], $fields);
    }

    /**
     * @param QueryBuilder $builder
     * @return void
     */
    public function mutateQuery(QueryBuilder $builder): void
    {
        $builder->select(...$this->fields);
    }
}