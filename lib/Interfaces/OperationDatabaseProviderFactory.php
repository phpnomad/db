<?php

namespace PHPNomad\Database\Interfaces;

use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\OperationCacheableService;
use PHPNomad\Database\Services\OperationEventStrategy;

/** Creates a resource-bound provider for one coordinated operation. */
interface OperationDatabaseProviderFactory
{
    public function create(
        DatabaseHandler $handler,
        QueryStrategy $queryStrategy,
        OperationCacheableService $cache,
        OperationEventStrategy $events
    ): DatabaseServiceProvider;
}
