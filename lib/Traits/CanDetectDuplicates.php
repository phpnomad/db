<?php

namespace PHPNomad\Database\Traits;

use PHPNomad\Database\Exceptions\RecordNotFoundException;
use PHPNomad\Database\Factories\TableProcessor;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\DuplicateEntryException;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\Datastore;
use PHPNomad\Utils\Helpers\Arr;

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