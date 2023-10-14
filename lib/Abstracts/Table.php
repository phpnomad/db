<?php

namespace Phoenix\Database\Abstracts;

use Cassandra\Column;
use Phoenix\Database\Interfaces\QueryStrategy;
use Phoenix\Database\Interfaces\HasDatabaseDefaultCacheTtl;
use Phoenix\Database\Interfaces\HasGlobalDatabasePrefix;
use Phoenix\Database\Interfaces\HasLocalDatabasePrefix;
use Phoenix\Database\Interfaces\Table as CoreTable;
use Phoenix\Utils\Helpers\Str;

abstract class Table implements CoreTable
{
    protected HasDatabaseDefaultCacheTtl $defaultCacheTtlProvider;
    protected HasLocalDatabasePrefix $localPrefixProvider;
    protected HasGlobalDatabasePrefix $globalPrefixProvider;

    public function __construct(HasDatabaseDefaultCacheTtl $defaultCacheTtlProvider, HasLocalDatabasePrefix $localPrefixProvider, HasGlobalDatabasePrefix $globalPrefixProvider)
    {
        $this->defaultCacheTtlProvider = $defaultCacheTtlProvider;
        $this->localPrefixProvider = $localPrefixProvider;
        $this->globalPrefixProvider = $globalPrefixProvider;
    }

    /** @inheritDoc */
    public function getCacheTtl(): int
    {
        return $this->defaultCacheTtlProvider->getDatabaseDefaultCacheTtl();
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

    /**
     * Gets the table name, without a prefix.
     *
     * @return string
     */
    abstract public function getUnprefixedName(): string;
}
