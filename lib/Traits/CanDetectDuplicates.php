<?php

namespace Phoenix\Database\Traits;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\DuplicateEntryException;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Factories\Column;
use Phoenix\Database\Factories\TableProcessor;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\QueryStrategy;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Utils\Helpers\Arr;

trait CanDetectDuplicates
{
    protected Table $table;

    protected QueryBuilder $queryBuilder;
    protected QueryStrategy $queryStrategy;
    protected TableProcessor $tableProcessor;

    abstract protected function getBy(string $column, $value): DatabaseModel;

    /**
     * Looks up records that already have records in the specified columns.
     *
     * @param array $data
     * @return int[] list of existing item IDs.
     * @throws DatabaseErrorException
     * @throws RecordNotFoundException
     */
    protected function getDuplicates(array $data): array
    {
        $uniqueColumns = $this->tableProcessor->getUniqueColumns($this->table);
        $firstColumn = array_shift($uniqueColumns);
        $query = $this->queryBuilder
            ->useTable($this->table)
            ->select('id')
            ->from();

        if ($dataValue = Arr::get($data, $firstColumn->getName())) {
            $query->where($firstColumn->getName(), '=', $dataValue);
        }

        foreach ($uniqueColumns as $uniqueColumn) {
            if ($dataValue = Arr::get($data, $uniqueColumn->getName())) {
                $query->orWhere($firstColumn->getName(), '=', $dataValue);
            }
        }

        return Arr::pluck($this->queryStrategy->query($query), 'id');
    }

    /**
     * @param array $data
     * @param int|null $updateId
     * @return void
     * @throws DuplicateEntryException
     * @throws DatabaseErrorException
     */
    protected function maybeThrowForDuplicates(array $data, ?int $updateId = null): void
    {
        try {
            $duplicates = Arr::filter($this->getDuplicates($data), function (int $existingId) use ($updateId) {
                return $existingId !== $updateId;
            });
        } catch (RecordNotFoundException $e) {
            // Bail if no records were found.
            return;
        }

        if (!empty($duplicates)) {
            $duplicateString = Arr::process($duplicates)
                ->setSeparator(', ')
                ->toString();

            throw new DuplicateEntryException('Database operation stopped early because duplicate entries were detected. Items: ' . $duplicateString . '.');
        }
    }
}