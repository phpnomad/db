<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;

/**
 * Adapter for IdentityRowModel that exposes the full row — the standard
 * full-serialization case.
 *
 * @implements ModelAdapter<DataModel>
 */
class IdentityRowModelAdapter implements ModelAdapter
{
    /**
     * @param array<string, mixed> $array
     */
    public function toModel(array $array): DataModel
    {
        return new IdentityRowModel($array);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(DataModel $model): array
    {
        return $model instanceof IdentityRowModel ? $model->toRow() : [];
    }
}
