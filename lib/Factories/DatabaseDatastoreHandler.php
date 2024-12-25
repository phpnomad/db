<?php

namespace PHPNomad\Database\Factories;

use PHPNomad\Database\Interfaces\DatabaseContextProvider;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;
use PHPNomad\Datastore\Interfaces\Datastore;
use PHPNomad\Datastore\Interfaces\DatastoreHasCounts;
use PHPNomad\Datastore\Interfaces\DatastoreHasWhere;

class DatabaseDatastoreHandler implements Datastore, DatastoreHasWhere, DatastoreHasCounts
{
    use WithDatastoreHandlerMethods;

    protected DatabaseServiceProvider $serviceProvider;
    protected DatabaseContextProvider $contextProvider;
    protected TableSchemaService      $tableSchemaService;

    public function __construct(
        DatabaseServiceProvider $serviceProvider,
        DatabaseContextProvider $contextProvider,
        TableSchemaService      $tableSchemaService
    )
    {
        $this->tableSchemaService = $tableSchemaService;
        $this->serviceProvider = $serviceProvider;
        $this->contextProvider = $contextProvider;
    }
}