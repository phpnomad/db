<?php

namespace Phoenix\Database\Traits;

namespace Phoenix\Database\Traits;

use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Interfaces\ModelAdapter;

/**
 * @template TModelAdapter of ModelAdapter
 * @template TModel of DatabaseModel
 * @method static instance()
 * @method getContainerInstance(string $instance)
 */
trait WithModelAdapterFacadeMethods
{
    /**
     * @param array $data
     * @return TModel
     */
    public static function buildModelFromArray(array $data): DatabaseModel
    {
        return static::instance()->getContainedModelAdapter()->toModel($data);
    }

    /**
     * @param TModel $model
     * @return array
     */
    public static function getDataFromModel(DatabaseModel $model): array
    {
        return static::instance()->getContainedModelAdapter()->toArray($model);
    }

    /**
     * Gets the model adapter
     *
     * @return TModelAdapter
     */
    protected function getContainedModelAdapter(): ModelAdapter
    {
        return $this->getContainerInstance($this->getModelAdapterInstance());
    }

    /**
     * @return class-string<ModelAdapter>
     */
    abstract protected function getModelAdapterInstance(): string;
}