<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Mutators\Interfaces\QueryMutator;

/**
 * @template TModel of DatabaseModel
 */
interface Query
{
    /**
     * Queries data, leveraging the cache.
     *
     * @param QueryMutator ...$args List of args used to make this query.
     * @return TModel[]|int[]
     */
    public function query(QueryMutator ...$args): array;
}