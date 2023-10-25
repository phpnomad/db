<?php

namespace Phoenix\Database\Interfaces;

interface QueryStrategy
{
    /**
     * Executes a database query.
     *
     * @param QueryBuilder $builder. A fully-escaped, safe query string.
     * @return array
     */
    public function query(QueryBuilder $builder): array;
}