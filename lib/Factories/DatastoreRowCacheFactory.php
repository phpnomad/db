<?php

namespace PHPNomad\Database\Factories;

use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\DatastoreRowCache;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

/**
 * Builds the per-table DatastoreRowCache collaborator. Lives on the
 * DatabaseServiceProvider (like CacheableService) so containers can swap the
 * row-cache behavior without touching the datastore trait.
 *
 * @see \PHPNomad\Database\Services\DatastoreRowCache
 */
class DatastoreRowCacheFactory
{
    protected CacheableService $cacheableService;
    protected LoggerStrategy $logger;

    public function __construct(CacheableService $cacheableService, LoggerStrategy $logger)
    {
        $this->cacheableService = $cacheableService;
        $this->logger = $logger;
    }

    /**
     * @param class-string $model
     */
    public function make(Table $table, string $model, bool $useGenerations = true): DatastoreRowCache
    {
        return new DatastoreRowCache($this->cacheableService, $this->logger, $table, $model, $useGenerations);
    }
}
