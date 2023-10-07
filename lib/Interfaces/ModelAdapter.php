<?php

namespace Phoenix\Database\Interfaces;

/**
 * @template TModel of DatabaseModel
 */
interface ModelAdapter
{
    /**
     * @param TModel $model
     * @return array
     */
    public function toArray(DatabaseModel $model): array;

    /**
     * @param array $array
     * @return TModel
     */
    public function toModel(array $array): DatabaseModel;
}