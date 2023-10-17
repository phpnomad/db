<?php

namespace Phoenix\Database\Traits;

use Phoenix\Database\Exceptions\DuplicateEntryException;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Factories\Column;
use Phoenix\Database\Factories\TableProcessor;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Interfaces\HasIntIdentity;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Utils\Helpers\Arr;

trait CanDetectDuplicates
{
    protected Table $table;

    abstract protected function getBy(string $column, $value): DatabaseModel;

    /**
     * @return Column[]
     */
    abstract protected function getUniqueColumns(): array;

    /**
     * @param array $data
     * @return array list of existing items.
     */
    protected function getDuplicates(array $data): array
    {
        $result = [];
        foreach ($this->getUniqueColumns() as $uniqueColumn) {
            try {
                $item = $this->getBy($uniqueColumn->getName(), Arr::get($data, $uniqueColumn->getName()));
                $result[$uniqueColumn->getName()] = $item;
            } catch (RecordNotFoundException $e) {
                // Ignore.
                continue;
            }
        }

        return $result;
    }

    /**
     * @param array $data
     * @param int|null $existingId
     * @return void
     * @throws DuplicateEntryException
     */
    protected function maybeThrowForDuplicates(array $data, ?int $existingId = null): void
    {
        $duplicates = Arr::filter($this->getDuplicates($data), function (HasIntIdentity $identity) use ($existingId) {
            $identity->getId() !== $existingId;
        });

        if (!empty($duplicates)) {
            $duplicateString = Arr::process($duplicates)
                ->each(function (HasIntIdentity $identity, string $column) {
                    return "Item ID {$identity->getId()} has duplicate $column value";
                })
                ->setSeparator(', and')
                ->toString();

            throw new DuplicateEntryException('Database operation stopped early because duplicate entries were detected: ' . $duplicateString . '.');
        }
    }
}