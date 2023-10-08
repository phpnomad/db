<?php

namespace Phoenix\Database\Mutators;

use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Mutators\Interfaces\QueryMutator;
use Phoenix\Utils\Helpers\Arr;

/**
 * Sets the limit on the query.
 */
class OrderBy implements QueryMutator
{
    /**
     * @var array
     */
    protected array $order;
    protected string $field;

    /**
     * @param string $field
     * @param string $order
     */
    public function __construct(string $field, string $order)
    {
        $this->field = $field;
        $this->order = $order;
    }

    public function mutateQuery(QueryBuilder $builder): void
    {
        $builder->orderBy($this->field, $this->order);
    }
}