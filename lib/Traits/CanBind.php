<?php

namespace Phoenix\Database\Traits;

namespace Phoenix\Database\Traits;

use Phoenix\Database\Abstracts\JunctionTable;
use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\DuplicateEntryException;
use Phoenix\Database\Exceptions\RecordNotFoundException;
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
            $this->queryStrategy->query(
                (clone $this->queryBuilder)
                    ->useTable($this->junctionTable)
                    ->reset()
                    ->select($left)
                    ->from()
                    ->limit(1)
                    ->where($left, '=', $leftValue)
                    ->andWhere($right, '=', $rightValue)
            );

            throw new DuplicateEntryException("Junction table {$this->junctionTable->getName()} already has a record binding $left $leftValue to $right $rightValue.");
        } catch (RecordNotFoundException $e) {
            // Ignore. This is expected in this case.
        }

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