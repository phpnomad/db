<?php

namespace Phoenix\Database\Traits;

namespace Phoenix\Database\Traits;

use Phoenix\Database\Abstracts\JunctionTable;
use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\QueryStrategy;
use Phoenix\Logger\Interfaces\LoggerStrategy;

trait CanBind
{
    protected QueryStrategy $queryStrategy;

    protected QueryBuilder $queryBuilder;

    protected JunctionTable $junctionTable;

    protected LoggerStrategy $loggerStrategy;

    /** @inheritDoc */
    public function bind(string $table, int $id, int $bindingId): void
    {
        $left = $this->junctionTable->getLeftColumnName();
        $right = $this->junctionTable->getRightColumnName();

        $leftValue = $table === $this->junctionTable->getLeftTable()->getName() ? $id : $bindingId;
        $rightValue = $table === $this->junctionTable->getRightTable()->getName() ? $id : $bindingId;

        try {
            $this->queryStrategy->create($this->junctionTable, [
                $left => $leftValue,
                $right => $rightValue
            ]);

        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e);
        }
    }

    /** @inheritDoc */
    public function unbind(string $table, int $id, int $bindingId): void
    {
        $left = $this->junctionTable->getLeftColumnName();
        $right = $this->junctionTable->getRightColumnName();

        $leftValue = $table === $this->junctionTable->getLeftTable()->getName() ? $id : $bindingId;
        $rightValue = $table === $this->junctionTable->getRightTable()->getName() ? $id : $bindingId;

        try {
            $this->queryStrategy->deleteWhere(
                $this->junctionTable,
                [
                    $left => $leftValue,
                    $right => $rightValue
                ]
            );
        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e);
        }
    }
}