<?php

namespace Phoenix\Database\Traits;

use Phoenix\Database\Abstracts\JunctionTable;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\QueryStrategy;

trait CanMakeJunctionQuery
{
    protected QueryStrategy $queryStrategy;

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
        } else{
            $select = $this->junctionTable->getRightColumnName();
            $where = $this->junctionTable->getLeftColumnName();
        }

        $this->queryBuilder
            ->select($select)
            ->where($where, '=', $id);

        return $this->queryStrategy->query($this->queryBuilder);
    }
}