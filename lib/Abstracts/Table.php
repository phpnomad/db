<?php

namespace Phoenix\Database\Abstracts;

use Phoenix\Database\Interfaces\HasCharsetProvider;
use Phoenix\Database\Interfaces\HasCollateProvider;
use Phoenix\Database\Interfaces\HasGlobalDatabasePrefix;
use Phoenix\Database\Interfaces\HasLocalDatabasePrefix;
use Phoenix\Database\Interfaces\Table as CoreTable;
use Phoenix\Database\Services\TableSchemaService;
use Phoenix\Utils\Helpers\Str;

abstract class Table implements CoreTable
{
    protected HasLocalDatabasePrefix $localPrefixProvider;
    protected HasGlobalDatabasePrefix $globalPrefixProvider;
    protected HasCharsetProvider $charsetProvider;
    protected HasCollateProvider $collateProvider;
    protected TableSchemaService $tableSchemaService;

    public function __construct(
        HasLocalDatabasePrefix  $localPrefixProvider,
        HasGlobalDatabasePrefix $globalPrefixProvider,
        HasCharsetProvider      $charsetProvider,
        HasCollateProvider      $collateProvider,
        TableSchemaService      $tableSchemaService
    )
    {
        $this->tableSchemaService = $tableSchemaService;
        $this->localPrefixProvider = $localPrefixProvider;
        $this->globalPrefixProvider = $globalPrefixProvider;
        $this->charsetProvider = $charsetProvider;
        $this->collateProvider = $collateProvider;
    }

    /**
     * Retrieves the database table name.
     *
     * @return string
     */
    public function getName(): string
    {
        return Str::append($this->globalPrefixProvider->getGlobalDatabasePrefix(), '_')
            . Str::append($this->localPrefixProvider->getLocalDatabasePrefix(), '_')
            . $this->getUnprefixedName();
    }

    /** @inheritdoc */
    abstract public function getUnprefixedName(): string;

    /**
     * Get the charset of the table.
     *
     * @return ?string
     */
    public function getCharset(): ?string
    {
        return $this->charsetProvider->getCharset();
    }

    /**
     * Get the collation of the table.
     *
     * @return ?string
     */
    public function getCollation(): ?string
    {
        return $this->collateProvider->getCollation();
    }

    /** @inheritDoc */
    public function getFieldsForIdentity(): array
    {
        return [$this->tableSchemaService->getPrimaryColumnForTable($this)->getName()];
    }
}
