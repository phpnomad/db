<?php

namespace Phoenix\Database\Providers;

use Phoenix\Cache\Services\CacheableService;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\QueryStrategy;
use Phoenix\Logger\Interfaces\LoggerStrategy;

class DatabaseServiceProvider
{
    public LoggerStrategy $loggerStrategy;
    public QueryStrategy $queryStrategy;
    public CacheableService $cacheableService;

    public QueryBuilder $queryBuilder;

    public function __construct(
        LoggerStrategy   $loggerStrategy,
        QueryStrategy    $queryStrategy,
        QueryBuilder     $queryBuilder,
        CacheableService $cacheableService
    )
    {
        $this->loggerStrategy = $loggerStrategy;
        $this->queryStrategy = $queryStrategy;
        $this->queryBuilder = $queryBuilder;
        $this->cacheableService = $cacheableService;
    }
}