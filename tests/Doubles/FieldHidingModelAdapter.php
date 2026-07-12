<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;

/**
 * Adapter that exposes the row EXCEPT for named fields — the
 * partial-serialization case where a lookup is only partly verifiable.
 *
 * @implements ModelAdapter<DataModel>
 */
class FieldHidingModelAdapter implements ModelAdapter
{
    /**
     * @param array<int, string> $hiddenFields
     */
    public function __construct(private array $hiddenFields = [])
    {
    }

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
        $row = $model instanceof IdentityRowModel ? $model->toRow() : [];

        return array_diff_key($row, array_flip($this->hiddenFields));
    }
}
