<?php

namespace PHPNomad\Database\Abstracts;

use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\Datastore;
use PHPNomad\Datastore\Interfaces\DatastoreHasCounts;
use PHPNomad\Datastore\Interfaces\DatastoreHasPrimaryKey;
use PHPNomad\Datastore\Interfaces\DatastoreHasWhere;

abstract class IdentifiableDatabaseDatastoreHandler implements Datastore, DatastoreHasPrimaryKey, DatastoreHasWhere, DatastoreHasCounts
{
    use WithDatastoreHandlerMethods;

    protected DatabaseServiceProvider $serviceProvider;
    protected TableSchemaService $tableSchemaService;

    /** @inheritDoc */
    public function find($id): DataModel
    {
        return $this->findFromCompound(['id' => $id]);
    }

    /** @inheritDoc */
    public function findMultiple(array $ids): array
    {
        return $this->andWhere([['column' => 'id', 'operator' => 'IN', 'value' => $ids]]);
    }

    /** @inheritDoc */
    public function delete($id): void
    {
        $this->deleteWhere([['column' => 'id', 'operator' => '=', 'value' => $id]]);
    }

    /** @inheritDoc */
    public function update($id, array $attributes): void
    {
        $this->updateCompound(['id' => $id], $attributes);
    }
}