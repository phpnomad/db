<?php

namespace Phoenix\Database\Abstracts;

use Phoenix\Database\Interfaces\HasDatabaseDefaultCacheTtl;
use Phoenix\Database\Interfaces\HasLocalDatabasePrefix;
use Phoenix\Database\Interfaces\Table as CoreTable;

abstract class Table implements CoreTable
{
    protected HasDatabaseDefaultCacheTtl $defaultCacheTtlProvider;
    protected HasLocalDatabasePrefix $prefixProvider;

    public function __construct(HasDatabaseDefaultCacheTtl $defaultCacheTtlProvider, HasLocalDatabasePrefix $prefixProvider )
    {
        $this->defaultCacheTtlProvider = $defaultCacheTtlProvider;
        $this->prefixProvider = $prefixProvider;
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
        $prefix = $this->prefixProvider->getDatabasePrefix();

        if (!empty($prefix)) {
            $prefix = $prefix . '_';
        }

        return $prefix . $this->getUnprefixedName();
    }

    /**
     * Gets the table name, without a prefix.
     *
     * @return string
     */
    abstract public function getUnprefixedName(): string;
}
