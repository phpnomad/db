<?php

namespace PHPNomad\Database\Services;

use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\Table as TableInterface;
use PHPNomad\Utils\Helpers\Arr;

class TableSchemaService
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
    public function getJunctionColumnNameFromTable(TableInterface $table): string
    {
        return $table->getSingularUnprefixedName() . ucfirst($this->getPrimaryColumnForTable($table)->getName());
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