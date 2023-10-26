<?php

namespace Phoenix\Database\Traits;

use Phoenix\Cache\Traits\WithInstanceCache;
use Phoenix\Database\Factories\DatabaseDatastoreHandler;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Database\Services\JunctionTableNamingService;
use Phoenix\Datastore\Factories\JunctionContextProvider as JunctionContextProviderFactory;
use Phoenix\Datastore\Interfaces\JunctionContextProvider;

trait WithDatabaseJunctionContextProvider
{
    use WithInstanceCache;

    protected DatabaseDatastoreHandler $handler;
    protected Table $table;
    protected JunctionTableNamingService $junctionTableNamingService;

    /**
     * The context resource.
     *
     * @return string
     */
    abstract protected function getResource(): string;

    /**
     * @return JunctionContextProvider
     */
    public function getJunctionContextProvider(): JunctionContextProvider
    {
        return $this->getFromInstanceCache('databaseJunctionContextProvider', fn() => new JunctionContextProviderFactory(
            $this->getResource(),
            $this->handler,
            $this->junctionTableNamingService->getColumnNameFromTable($this->table),
        ));
    }
}