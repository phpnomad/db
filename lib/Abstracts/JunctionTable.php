<?php

namespace PHPNomad\Database\Abstracts;

use PHPNomad\Cache\Traits\WithInstanceCache;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\HasCharsetProvider;
use PHPNomad\Database\Interfaces\HasCollateProvider;
use PHPNomad\Database\Interfaces\HasGlobalDatabasePrefix;
use PHPNomad\Database\Interfaces\HasLocalDatabasePrefix;
use PHPNomad\Database\Interfaces\Table as TableInterface;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Utils\Helpers\Arr;
use RuntimeException;

abstract class JunctionTable extends Table
{
    use WithInstanceCache;

    protected Table $rightTable;
    protected Table $leftTable;

    public function __construct(
        HasLocalDatabasePrefix  $localPrefixProvider,
        HasGlobalDatabasePrefix $globalPrefixProvider,
        HasCharsetProvider      $charsetProvider,
        HasCollateProvider      $collateProvider,
        TableSchemaService      $tableSchemaService,
        Table                   $leftTable,
        Table                   $rightTable
    )
    {
        $args = func_get_args();
        $this->rightTable = array_pop($args);
        $this->leftTable = array_pop($args);
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
        return $this->getFromInstanceCache('unprefixedName', fn() => lcfirst(
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
        return $this->tableSchemaService->getJunctionColumnNameFromTable($this->leftTable);
    }

    /**
     * Gets the right table column name.
     *
     * @return string
     */
    public function getRightColumnName(): string
    {
        return $this->tableSchemaService->getJunctionColumnNameFromTable($this->rightTable);
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
        $primaryColumns = $this->tableSchemaService->getPrimaryColumnsForTable($references);

        // Find the corresponding primary column by the column name
        $primaryColumn = Arr::find(
            $primaryColumns,
            fn(Column $column) => $column->getName() === $columnName
        );

        if ($primaryColumn === null) {
            throw new RuntimeException("Primary key column with name: $columnName does not exist.");
        }

        return new Index(
            [$columnName],
            null,
            'FOREIGN KEY',
            "REFERENCES {$references->getName()}({$primaryColumn->getName()})"
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

    /** @inheritDoc */
    public function getFieldsForIdentity(): array
    {
        return [$this->getLeftColumnName(), $this->getRightColumnName()];
    }
}