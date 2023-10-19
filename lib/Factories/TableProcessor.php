<?php

namespace Phoenix\Database\Factories;

use Phoenix\Cache\Interfaces\PersistentCacheStrategy;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Integrations\WordPress\Cache\ObjectCacheStrategy;
use Phoenix\Utils\Helpers\Arr;
use Phoenix\Utils\Processors\ListFilter;

class TableProcessor
{
    protected ObjectCacheStrategy $cacheStrategy;

    public function __construct(
        ObjectCacheStrategy $cacheStrategy
    )
    {
        $this->cacheStrategy = $cacheStrategy;
    }

    /**
     * Gets a list of columns that are marked as unique.
     *
     * @param Table $table
     * @return Column[]
     */
    public function getUniqueColumns(Table $table): array
    {
        return $this->cacheStrategy->load($this->getCacheKey($table) . '_unique_columns', function () use($table) {
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

    protected function getCacheKey(Table $table): string
    {
        return 'table_' . $table->getName() . $table->getTableVersion();
    }
}