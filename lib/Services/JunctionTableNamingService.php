<?php

namespace Phoenix\Database\Services;

use Phoenix\Cache\Enums\Operation;
use Phoenix\Cache\Services\CacheableService;
use Phoenix\Database\Factories\Column;
use Phoenix\Database\Interfaces\Table as TableInterface;
use Phoenix\Utils\Helpers\Arr;

class JunctionTableNamingService
{
    protected CacheableService $cacheableService;

    public function __construct(CacheableService $cacheableService)
    {
        $this->cacheableService = $cacheableService;
    }

    /**
     * @param TableInterface $table
     * @return Column
     */
    public function getPrimaryColumnForTable(TableInterface $table): Column
    {
        return $this->cacheableService->getWithCache(
            Operation::Read,
            $this->getCacheContext($table->getName() . 'PrimaryColumn'),
            fn() => Arr::find(
                $table->getColumns(),
                fn(Column $column) => Arr::hasValues($column->getAttributes(), 'PRIMARY KEY')
            )
        );
    }

    /**
     * Gets the column name from the table. Uses the table name with the primary column name.
     *
     * @param TableInterface $table
     * @return string
     */
    public function getColumnNameFromTable(TableInterface $table): string
    {
        return $table->getUnprefixedName() . ucfirst($this->getPrimaryColumnForTable($table)->getName());
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