<?php

namespace PHPNomad\Database\Interfaces;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;

interface ConsistentReadQueryStrategy
{
    /**
     * Executes a database query through a read path that can see preceding writes.
     *
     * @param QueryBuilder $builder A fully-escaped query builder.
     * @return array
     * @throws DatastoreErrorException
     */
    public function queryConsistently(QueryBuilder $builder): array;
}
