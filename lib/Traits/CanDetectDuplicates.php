<?php

namespace Phoenix\Database\Traits;

use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Factories\TableProcessor;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Datastore\Exceptions\DatastoreErrorException;
use Phoenix\Datastore\Exceptions\DuplicateEntryException;
use Phoenix\Datastore\Interfaces\DataModel;
use Phoenix\Datastore\Interfaces\Datastore;
use Phoenix\Utils\Helpers\Arr;

trait CanDetectDuplicates
{
    protected Table $table;

    protected QueryBuilder   $queryBuilder;
    protected Datastore      $queryStrategy;
    protected TableProcessor $tableProcessor;

    abstract protected function getBy(string $column, $value): DataModel;

    /**
     * Looks up records that already have records in the specified columns.
     *
     * @param array $data
     * @return int[] list of existing item IDs.
     * @throws DatastoreErrorException
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

        return Arr::pluck($this->datastore->query($query), 'id');
    }

    /**
     * @param array $data
     * @param int|null $updateId
     * @return void
     * @throws DuplicateEntryException
     * @throws DatastoreErrorException
     */
    protected function maybeThrowForDuplicates(array $data, ?int $updateId = null): void
    {
        try {
            $duplicates = Arr::filter($this->getDuplicates($data), fn(int $existingId) => $existingId !== $updateId);
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