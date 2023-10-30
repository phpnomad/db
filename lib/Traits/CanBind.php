<?php

namespace PHPNomad\Database\Traits;

namespace PHPNomad\Database\Traits;

use PHPNomad\Database\Abstracts\JunctionTable;
use PHPNomad\Database\Exceptions\RecordNotFoundException;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\DuplicateEntryException;
use PHPNomad\Datastore\Interfaces\Datastore;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

trait CanBind
{
    protected Datastore $queryStrategy;

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

        } catch (DatastoreErrorException $e) {
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
        } catch (DatastoreErrorException $e) {
            $this->loggerStrategy->logException($e);
        }
    }
}