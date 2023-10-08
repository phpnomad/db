<?php

namespace Phoenix\Database\Mutators;

use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Mutators\Interfaces\QueryMutator;

/**
 * Sets the limit on the query.
 */
class Limit implements QueryMutator
{
    protected int $limit;

    /**
     * @param positive-int $limit
     */
    public function __construct(int $limit)
    {
        $this->limit = $limit;
    }

    public function mutateQuery(QueryBuilder $builder): void
    {
        $builder->limit($this->limit);
    }
}