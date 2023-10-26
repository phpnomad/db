<?php

namespace Phoenix\Database\Abstracts;

use Phoenix\Cache\Enums\Operation;
use Phoenix\Cache\Services\CacheableService;
use Phoenix\Cache\Traits\WithInstanceCache;
use Phoenix\Database\Factories\Column;
use Phoenix\Database\Factories\Index;
use Phoenix\Database\Interfaces\HasCharsetProvider;
use Phoenix\Database\Interfaces\HasCollateProvider;
use Phoenix\Database\Interfaces\HasGlobalDatabasePrefix;
use Phoenix\Database\Interfaces\HasLocalDatabasePrefix;
use Phoenix\Database\Interfaces\Table as TableInterface;
use Phoenix\Database\Services\JunctionTableNamingService;
use Phoenix\Utils\Helpers\Arr;

abstract class JunctionTable extends Table
{
    use WithInstanceCache;
    protected Table $rightTable;
    protected Table $leftTable;
    protected $junctionTableNamingService;

    public function __construct(
        HasLocalDatabasePrefix     $localPrefixProvider,
        HasGlobalDatabasePrefix    $globalPrefixProvider,
        HasCharsetProvider         $charsetProvider,
        HasCollateProvider         $collateProvider,
        JunctionTableNamingService $junctionTableNamingService,
        Table                      $leftTable,
        Table                      $rightTable
    )
    {
        $args = func_get_args();
        $this->rightTable = array_pop($args);
        $this->leftTable = array_pop($args);
        $this->junctionTableNamingService = array_pop($args);
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

    /**
     * Gets the left table.
     *
     * @return TableInterface
     */
    public function getLeftTable(): TableInterface
    {
        return $this->leftTable;
    }

    /**
     * Gets the right table.
     *
     * @return TableInterface
     */
    public function getRightTable(): TableInterface
    {
        return $this->rightTable;
    }

    /** @inheritDoc */
    public function getAlias(): string
    {
        return $this->getFromInstanceCache('alias', fn() => lcfirst(
            Arr::process($this->getTables())
                ->map(fn(Table $table) => ucFirst($table->getAlias()))
                ->setSeparator('')
                ->toString()
        ));
    }

    /** @inheritDoc */
    public function getUnprefixedName(): string
    {
        return $this->getFromInstanceCache('unprefixedName', lcfirst(
            Arr::process($this->getTables())
                ->map(fn(Table $table) => ucfirst($table->getUnprefixedName()))
                ->setSeparator('')
                ->toString()
        ));
    }

    /**
     * Gets the left table column name.
     *
     * @return string
     */
    public function getLeftColumnName(): string
    {
        return $this->junctionTableNamingService->getColumnNameFromTable($this->leftTable);
    }

    /**
     * Gets the right table column name.
     *
     * @return string
     */
    public function getRightColumnName(): string
    {
        return $this->junctionTableNamingService->getColumnNameFromTable($this->rightTable);
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
     * @param TableInterface $references
     * @return Index
     */
    protected function buildForeignKeyFor(string $columnName, TableInterface $references): Index
    {
        return new Index(
            [$columnName],
            null,
            'FOREIGN KEY',
            "REFERENCES {$references->getName()}({$this->junctionTableNamingService->getPrimaryColumnForTable($references)->getName()})"
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
}