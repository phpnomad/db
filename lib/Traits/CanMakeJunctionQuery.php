<?php

namespace PHPNomad\Database\Traits;

use PHPNomad\Database\Abstracts\JunctionTable;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Datastore\Interfaces\Datastore;
use PHPNomad\Utils\Helpers\Arr;

trait CanMakeJunctionQuery
{
    protected Datastore $queryStrategy;

    protected QueryBuilder $queryBuilder;

    protected JunctionTable $junctionTable;

    /** @inheritDoc */
    public function getIdsFromTable(string $tableName, int $id, int $limit, int $offset): array
    {
        $this->queryBuilder
            ->useTable($this->junctionTable)
            ->limit($limit)
            ->offset($offset)
            ->from();

        if ($this->junctionTable->getLeftTable()->getName() === $tableName) {
            $select = $this->junctionTable->getLeftColumnName();
            $where = $this->junctionTable->getRightColumnName();
        } else {
            $select = $this->junctionTable->getRightColumnName();
            $where = $this->junctionTable->getLeftColumnName();
        }

        $this->queryBuilder
            ->select($select)
            ->where($where, '=', $id);

        return Arr::cast(Arr::pluck($this->queryStrategy->query($this->queryBuilder), $select), 'int');
    }
}