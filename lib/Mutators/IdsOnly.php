<?php

namespace Phoenix\Database\Mutators;

use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Mutators\Interfaces\QueryMutator;
use Phoenix\Utils\Helpers\Arr;

/**
 * Sets the limit on the query.
 */
class IdsOnly implements QueryMutator
{
    /**
     * @param QueryBuilder $builder
     * @return void
     */
    public function mutateQuery(QueryBuilder $builder): void
    {
        $builder->select('id');
    }
}