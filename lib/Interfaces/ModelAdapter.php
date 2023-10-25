<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Datastore\Interfaces\DataModel;

/**
 * @template TModel of DataModel
 */
interface ModelAdapter
{
    /**
     * @param TModel $model
     * @return array
     */
    public function toArray(DataModel $model): array;

    /**
     * @param array $array
     * @return TModel
     */
    public function toModel(array $array): DataModel;
}