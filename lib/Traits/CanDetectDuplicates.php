<?php

namespace Phoenix\Database\Traits;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\DuplicateEntryException;
use Phoenix\Database\Factories\Column;
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

    abstract protected function getBy(string $column, $value): DatabaseModel;

    /**
     * @return Column[]
     */
    abstract protected function getUniqueColumns(): array;

    /**
     * Looks up records that already have records in the specified columns.
     *
     * @param array $data
     * @return int[] list of existing item IDs.
     * @throws DatabaseErrorException
     */
    protected function getDuplicates(array $data): array
    {
        $uniqueColumns = $this->getUniqueColumns();
        $firstColumn = array_shift($uniqueColumns);
        $query = $this->queryBuilder
            ->useTable($this->table)
            ->select('id');

        if ($dataValue = Arr::get($data, $firstColumn->getName())) {
            $query->where($firstColumn->getName(), '=', $dataValue);
        }

        foreach ($uniqueColumns as $uniqueColumn) {
            if ($dataValue = Arr::get($data, $uniqueColumn->getName())) {
                $query->orWhere($firstColumn->getName(), '=', $dataValue);
            }
        }

        return $this->queryStrategy->query($query);
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
        $duplicates = Arr::filter($this->getDuplicates($data), function (int $existingId) use ($updateId) {
            return $existingId !== $updateId;
        });

        if (!empty($duplicates)) {
            $duplicateString = Arr::process($duplicates)
                ->setSeparator(', ')
                ->toString();

            throw new DuplicateEntryException('Database operation stopped early because duplicate entries were detected. Items: ' . $duplicateString . '.');
        }
    }
}