<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;

/**
 * Adapter that exposes nothing — the narrow-serialization case lookup
 * verification and identity derivation have to survive.
 */
class HidingModelAdapter implements ModelAdapter
{
    public function toModel(array $array): DataModel
    {
        return new IdentityRowModel($array);
    }

    public function toArray(DataModel $model): array
    {
        return [];
    }
}
