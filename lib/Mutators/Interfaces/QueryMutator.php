<?php

namespace Phoenix\Database\Mutators\Interfaces;

use Phoenix\Database\Interfaces\QueryBuilder;

interface QueryMutator
{
    /**
     * @param QueryBuilder $builder
     * @return void
     */
    public function mutateQuery(QueryBuilder $builder): void;
}