<?php

namespace PHPNomad\Database\Factories;

use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\RowCache;
use PHPNomad\Database\Interfaces\RowCacheFactory;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\DatastoreRowCache;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

/**
 * Default RowCacheFactory: builds the per-table DatastoreRowCache
 * collaborator from the provider's cache service and logger.
 *
 * @see \PHPNomad\Database\Services\DatastoreRowCache
 */
class DatastoreRowCacheFactory implements RowCacheFactory
{
    protected CacheableService $cacheableService;
    protected LoggerStrategy $loggerStrategy;

    public function __construct(CacheableService $cacheableService, LoggerStrategy $loggerStrategy)
    {
        $this->cacheableService = $cacheableService;
        $this->loggerStrategy = $loggerStrategy;
    }

    /**
     * @inheritDoc
     *
     * @param ModelAdapter<DataModel> $modelAdapter
     */
    public function make(Table $table, string $model, ModelAdapter $modelAdapter, bool $useGenerations = true): RowCache
    {
        return new DatastoreRowCache($this->cacheableService, $this->loggerStrategy, $table, $model, $modelAdapter, $useGenerations);
    }
}
