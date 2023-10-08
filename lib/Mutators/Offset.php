<?php

namespace Phoenix\Database\Mutators;

use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Mutators\Interfaces\QueryMutator;

/**
 * Sets the limit on the query.
 */
class Offset implements QueryMutator
{
    protected int $offset;

    /**
     * @param positive-int $limit
     */
    public function __construct(int $limit)
    {
        $this->offset = $limit;
    }

    public function mutateQuery(QueryBuilder $builder): void
    {
        $builder->offset($this->offset);
    }
}