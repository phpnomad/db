<?php

namespace PHPNomad\Database\Providers;

use PHPNomad\Cache\Services\CacheableService;
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

    public function __construct(
        LoggerStrategy   $loggerStrategy,
        QueryStrategy    $queryStrategy,
        QueryBuilder     $queryBuilder,
        ClauseBuilder    $clauseBuilder,
        CacheableService $cacheableService,
        EventStrategy    $eventStrategy
    )
    {
        $this->clauseBuilder = $clauseBuilder;
        $this->loggerStrategy = $loggerStrategy;
        $this->queryStrategy = $queryStrategy;
        $this->queryBuilder = $queryBuilder;
        $this->cacheableService = $cacheableService;
        $this->eventStrategy = $eventStrategy;
    }

    /**
     * Make a provider for one operation without changing this provider.
     * Adapters may supply builders bound to their operation-owned resource.
     */
    public function forOperation(
        QueryStrategy $queryStrategy,
        ?QueryBuilder $queryBuilder = null,
        ?ClauseBuilder $clauseBuilder = null,
        ?CacheableService $cacheableService = null,
        ?EventStrategy $eventStrategy = null
    ): self
    {
        $provider = clone $this;
        $provider->queryStrategy = $queryStrategy;
        $provider->queryBuilder = $queryBuilder === null ? clone $this->queryBuilder : $queryBuilder;
        $provider->clauseBuilder = $clauseBuilder === null ? clone $this->clauseBuilder : $clauseBuilder;
        $provider->queryBuilder->reset();
        $provider->clauseBuilder->reset();
        $provider->cacheableService = $cacheableService ?: $this->cacheableService;
        $provider->eventStrategy = $eventStrategy ?: $this->eventStrategy;

        return $provider;
    }
}
