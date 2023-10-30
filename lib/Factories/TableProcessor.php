<?php

namespace PHPNomad\Database\Factories;

use PHPNomad\Cache\Interfaces\PersistentCacheStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Integrations\WordPress\Cache\ObjectCacheStrategy;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Processors\ListFilter;

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