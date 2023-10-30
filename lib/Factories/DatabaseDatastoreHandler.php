<?php

namespace PHPNomad\Database\Factories;

use PHPNomad\Database\Interfaces\DatabaseContextProvider;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;
use PHPNomad\Datastore\Interfaces\Datastore;

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