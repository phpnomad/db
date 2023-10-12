<?php

namespace Phoenix\Database\Interfaces;

interface HasDatabaseDefaultCacheTtl
{
    /**
     * Returns the default cache TTL
     *
     * @return int
     */
    public function getDatabaseDefaultCacheTtl(): int;
}