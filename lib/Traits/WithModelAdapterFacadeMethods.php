<?php

namespace Phoenix\Database\Traits;

namespace Phoenix\Database\Traits;

use Phoenix\Database\Interfaces\ModelAdapter;
use Phoenix\Datastore\Interfaces\DataModel;

/**
 * @template TModelAdapter of ModelAdapter
 * @template TModel of DataModel
 * @method static instance()
 * @method getContainerInstance(string $instance)
 */
trait WithModelAdapterFacadeMethods
{
    /**
     * @param array $data
     * @return TModel
     */
    public static function buildModelFromArray(array $data): DataModel
    {
        return static::instance()->getContainedModelAdapter()->toModel($data);
    }

    /**
     * @param TModel $model
     * @return array
     */
    public static function getDataFromModel(DataModel $model): array
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