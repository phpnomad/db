<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;

/**
 * Adapter for IdentityRowModel that exposes the full row — the standard
 * full-serialization case.
 */
class IdentityRowModelAdapter implements ModelAdapter
{
    public function toModel(array $array): DataModel
    {
        return new IdentityRowModel($array);
    }

    public function toArray(DataModel $model): array
    {
        return $model instanceof IdentityRowModel ? $model->toRow() : [];
    }
}
