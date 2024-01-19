<?php

namespace PHPNomad\Database\Services;

use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table as TableInterface;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Processors\ListFilter;

class TableSchemaService
{
    protected CacheableService $cacheableService;

    public function __construct(CacheableService $cacheableService)
    {
        $this->cacheableService = $cacheableService;
    }

    /**
     * @param TableInterface $table
     * @return Column[]
     */
    public function getPrimaryColumnsForTable(TableInterface $table): array
    {
        return $this->cacheableService->getWithCache(
            Operation::Read,
            $this->getCacheContext($table->getName() . 'PrimaryColumns'),
            fn() => $this->findPrimaryColumns($table)
        );
    }

    /**
     * Locates the primary columns used for this table.
     *
     * @param TableInterface $table
     * @return Column[]
     */
    private function findPrimaryColumns(TableInterface $table): array
    {
        $primaryColumns = Arr::filter(
            $table->getColumns(),
            fn(Column $column) => Arr::hasValues($column->getAttributes(), 'PRIMARY KEY')
        );

        /** @var ?Index $primaryKeyIndex */
        $primaryKeyIndex = Arr::find(
            $table->getIndices(),
            fn(Index $index) => $index->getType() === 'PRIMARY KEY'
        );

        if ($primaryKeyIndex) {
            $primaryColumns = Arr::merge($primaryColumns, $this->getIndexColumns($table, $primaryKeyIndex));
        }

        return $primaryColumns;
    }

    /**
     * @param TableInterface $table
     * @param Index $index
     * @return Column[]
     */
    private function getIndexColumns(TableInterface $table, Index $index): array
    {
        return Arr::filter($table->getColumns(), fn(Column $column) => in_array($column->getName(), $index->getColumns()));
    }

    /**
     * Gets the unique columns in the specified table.
     *
     * @param TableInterface $table
     * @return Column[]
     */
    public function getUniqueColumns(TableInterface $table): array
    {
        return $this->cacheableService->getWithCache(
            Operation::Read,
            $this->getCacheContext($table->getName() . 'UniqueColumns'), function () use($table) {
            $uniqueIndices = (new ListFilter($table->getIndices()))
                ->equals('type', 'UNIQUE')
                ->filter();

            $columns = Arr::reduce(
                $uniqueIndices,
                fn (array $acc, Index $index) => array_merge($acc, $index->getColumns()),
                []
            );

            return (new ListFilter($table->getColumns()))
                ->in('name', ...$columns)
                ->filter();
        });
    }

    /**
     * Gets the column name from the table. Uses the table name with the primary column name.
     *
     * @param TableInterface $table
     * @return string
     */
    public function getJunctionColumnNameFromTable(TableInterface $table): string
    {
        return $table->getSingularUnprefixedName() . 'BindingId';
    }

    /**
     * Gets the cache key for this table. Used to cut back on processing when making this table.
     *
     * @param TableInterface $table
     * @param string $id
     * @return array
     */
    protected function getCacheContext(string $id): array
    {
        return ['for' => get_called_class(), 'id' => $id];
    }
}