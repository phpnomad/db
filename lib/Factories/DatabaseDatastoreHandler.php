<?php

namespace Phoenix\Database\Factories;

use Phoenix\Cache\Enums\Operation;
use Phoenix\Database\Providers\DatabaseContextProvider;
use Phoenix\Database\Providers\DatabaseServiceProvider;
use Phoenix\Database\Traits\WithDatastoreHandlerMethods;
use Phoenix\Datastore\Exceptions\DatastoreErrorException;
use Phoenix\Datastore\Interfaces\DataModel;
use Phoenix\Datastore\Interfaces\Datastore;
use Phoenix\Datastore\Interfaces\DatastoreHasPrimaryKey;
use Phoenix\Utils\Helpers\Arr;

class DatabaseDatastoreHandler implements Datastore
{
    use WithDatastoreHandlerMethods;

    protected DatabaseServiceProvider $serviceProvider;
    protected DatabaseContextProvider $contextProvider;

    public function __construct(
        DatabaseServiceProvider $serviceProvider,
        DatabaseContextProvider $contextProvider
    )
    {
        $this->serviceProvider = $serviceProvider;
        $this->contextProvider = $contextProvider;
    }
}