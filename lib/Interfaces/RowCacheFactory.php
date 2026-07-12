<?php

namespace PHPNomad\Database\Interfaces;

use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;

/**
 * Builds the per-table RowCache collaborator. The provider exposes this
 * contract (not a concrete factory) so containers can swap row-cache
 * behavior without touching the datastore trait.
 *
 * @see \PHPNomad\Database\Factories\DatastoreRowCacheFactory the default implementation
 */
interface RowCacheFactory
{
    /**
     * @param Table $table The table whose contexts the RowCache will build.
     * @param class-string<DataModel> $model The model class cache contexts are typed under.
     * @param ModelAdapter $adapter Used to verify alias-resolved rows against lookup values.
     * @param bool $useGenerations Whether contexts carry a per-table generation token
     *                             — see WithDatastoreHandlerMethods::shouldUseTableGenerations()
     *                             for what opting out costs.
     */
    public function make(Table $table, string $model, ModelAdapter $adapter, bool $useGenerations = true): RowCache;
}
