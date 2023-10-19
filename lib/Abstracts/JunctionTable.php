<?php

namespace Phoenix\Database\Abstracts;

use Phoenix\Cache\Interfaces\InMemoryCacheStrategy;
use Phoenix\Database\Factories\Column;
use Phoenix\Database\Factories\Index;
use Phoenix\Database\Interfaces\HasCharsetProvider;
use Phoenix\Database\Interfaces\HasCollateProvider;
use Phoenix\Database\Interfaces\HasDatabaseDefaultCacheTtl;
use Phoenix\Database\Interfaces\HasGlobalDatabasePrefix;
use Phoenix\Database\Interfaces\HasLocalDatabasePrefix;
use Phoenix\Utils\Helpers\Arr;
use Phoenix\Utils\Helpers\Str;

abstract class JunctionTable extends Table
{
    /**
     * @var mixed|null
     */
    protected InMemoryCacheStrategy $cacheStrategy;
    /**
     * @var mixed|null
     */
    protected Table $rightTable;
    /**
     * @var mixed|null
     */
    protected Table $leftTable;

    public function __construct(
        HasDatabaseDefaultCacheTtl $defaultCacheTtlProvider,
        HasLocalDatabasePrefix     $localPrefixProvider,
        HasGlobalDatabasePrefix    $globalPrefixProvider,
        HasCharsetProvider         $charsetProvider,
        HasCollateProvider         $collateProvider,
        InMemoryCacheStrategy      $cacheStrategy,
        Table                      $leftTable,
        Table                      $rightTable
    )
    {
        $args = func_get_args();
        $this->rightTable = array_pop($args);
        $this->leftTable = array_pop($args);
        $this->cacheStrategy = array_pop($args);
        parent::__construct(...$args);
    }

    /**
     * Fetches both the left and right table, as an array.
     *
     * @return array
     */
    protected function getTables(): array
    {
        return [$this->leftTable, $this->rightTable];
    }

    /** @inheritDoc */
    public function getAlias(): string
    {
        return $this->cacheStrategy->load(
            $this->getCacheKey('alias'),
            fn() => Arr::process($this->getTables())
                ->map(fn(Table $table) => $table->getAlias())
                ->setSeparator('_')
                ->toString()
        );
    }

    /** @inheritDoc */
    public function getUnprefixedName(): string
    {
        return Arr::process($this->getTables())
            ->map(fn(Table $table) => $table->getUnprefixedName())
            ->setSeparator('_')
            ->toString();
    }

    /**
     * @param Table $table
     * @return Column
     */
    protected function getPrimaryColumnForTable(Table $table): Column
    {
        return $this->cacheStrategy->load(
            $this->getCacheKey($table->getName() . '_primary_column'),
            fn() => Arr::find(
                $table->getColumns(),
                fn(Column $column) => Arr::hasValues($column->getAttributes(), 'PRIMARY KEY')
            )
        );
    }

    /**
     * Gets the column name from the table. Uses the table name with the primary column name.
     *
     * @param Table $table
     * @return string
     */
    protected function getColumnNameFromTable(Table $table): string
    {
        return $table->getUnprefixedName() . '_' . $this->getPrimaryColumnForTable($table)->getName();
    }

    /**
     * Gets the left table column name.
     *
     * @return string
     */
    protected function getLeftColumnName(): string
    {
        return $this->getColumnNameFromTable($this->leftTable);
    }

    /**
     * Gets the right table column name.
     *
     * @return string
     */
    protected function getRightColumnName(): string
    {
        return $this->getColumnNameFromTable($this->rightTable);
    }

    /**
     * Builds the compound key index.
     *
     * @return Index
     */
    protected function buildCompoundKey(): Index
    {
        return new Index(
            [
                $this->getRightColumnName(),
                $this->getLeftColumnName(),
            ],
            null,
            'PRIMARY KEY'
        );
    }

    /**
     * Builds a foreign key using the provided tables.
     *
     * @param string $columnName
     * @param Table $references
     * @return Index
     */
    protected function buildForeignKeyFor(string $columnName, Table $references): Index
    {
        return new Index(
            [$columnName],
            null,
            'FOREIGN KEY',
            "REFERENCES {$references->getName()}({$this->getPrimaryColumnForTable($references)->getName()})"
        );
    }

    /** @inheritDoc */
    public function getColumns(): array
    {
        return [
            new Column($this->getLeftColumnName(), 'BIGINT'),
            new Column($this->getRightColumnName(), 'BIGINT')
        ];
    }

    /** @inheritDoc */
    public function getIndices(): array
    {
        return [
            $this->buildCompoundKey(),
            $this->buildForeignKeyFor($this->getLeftColumnName(), $this->leftTable),
            $this->buildForeignKeyFor($this->getRightColumnName(), $this->rightTable),
        ];
    }

    /**
     * Gets the cache key for this table. Used to cut back on processing when making this table.
     *
     * @param string $append
     * @return string
     */
    protected function getCacheKey(string $append): string
    {
        return $this->getUnprefixedName() . '__' . $this->getTableVersion() . '__' . $append;
    }
}