<?php

namespace PHPNomad\Database\Providers;

use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Factories\DatastoreRowCacheFactory;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

class DatabaseServiceProvider
{
    public LoggerStrategy $loggerStrategy;
    public QueryStrategy $queryStrategy;
    public CacheableService $cacheableService;

    public QueryBuilder $queryBuilder;
    public ClauseBuilder $clauseBuilder;
    public EventStrategy $eventStrategy;
    public DatastoreRowCacheFactory $rowCacheFactory;

    public function __construct(
        LoggerStrategy   $loggerStrategy,
        QueryStrategy    $queryStrategy,
        QueryBuilder     $queryBuilder,
        ClauseBuilder    $clauseBuilder,
        CacheableService $cacheableService,
        EventStrategy    $eventStrategy,
        ?DatastoreRowCacheFactory $rowCacheFactory = null
    )
    {
        $this->clauseBuilder = $clauseBuilder;
        $this->loggerStrategy = $loggerStrategy;
        $this->queryStrategy = $queryStrategy;
        $this->queryBuilder = $queryBuilder;
        $this->cacheableService = $cacheableService;
        $this->eventStrategy = $eventStrategy;
        // Optional so existing six-argument construction keeps working; the
        // default factory composes from the same injected services. Prefer
        // binding the factory at the container level — this parameter is
        // slated to become required in the next major.
        $this->rowCacheFactory = $rowCacheFactory ?? new DatastoreRowCacheFactory($cacheableService, $loggerStrategy);
    }
}