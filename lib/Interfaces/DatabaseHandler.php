<?php

namespace PHPNomad\Database\Interfaces;

use PHPNomad\Database\Providers\DatabaseServiceProvider;

/** A database-backed datastore handler that can be cloned for one operation. */
interface DatabaseHandler
{
    public function getDatabaseTable(): Table;

    public function getDatabaseServiceProvider(): DatabaseServiceProvider;

    public function cloneForOperation(DatabaseServiceProvider $serviceProvider): DatabaseHandler;
}
