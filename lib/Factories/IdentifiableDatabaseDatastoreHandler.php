<?php

namespace Phoenix\Database\Factories;

use Phoenix\Database\Providers\DatabaseContextProvider;
use Phoenix\Database\Providers\DatabaseServiceProvider;
use Phoenix\Database\Traits\WithDatastoreHandlerMethods;
use Phoenix\Datastore\Interfaces\DataModel;
use Phoenix\Datastore\Interfaces\Datastore;
use Phoenix\Datastore\Interfaces\WithPrimaryKey;

class IdentifiableDatabaseDatastoreHandler implements Datastore, WithPrimaryKey
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

    /** @inheritDoc */
    public function find($id): DataModel
    {
        return $this->findFromCompound(['id' => $id]);
    }

    /** @inheritDoc */
    public function delete($id): void
    {
        $this->deleteWhere([['id', '=', $id]]);
    }

    /** @inheritDoc */
    public function update($id, array $attributes): void
    {
        $this->updateCompound(['id', '=', $id], $attributes);
    }
}