<?php

namespace PHPNomad\Database\Services;

use PHPNomad\Database\Interfaces\DatabaseHandler;
use PHPNomad\Database\Interfaces\OperationDatabaseProviderFactory;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Providers\DatabaseServiceProvider;

/** Default factory for adapters whose builders can be safely cloned. */
class CloningOperationDatabaseProviderFactory implements OperationDatabaseProviderFactory
{
    public function create(
        DatabaseHandler $handler,
        QueryStrategy $queryStrategy,
        OperationCacheableService $cache,
        OperationEventStrategy $events
    ): DatabaseServiceProvider {
        return $handler->getDatabaseServiceProvider()->forOperation(
            $queryStrategy,
            null,
            null,
            $cache,
            $events
        );
    }
}
