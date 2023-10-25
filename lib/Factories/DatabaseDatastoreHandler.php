<?php

namespace Phoenix\Database\Factories;

use Phoenix\Cache\Enums\Operation;
use Phoenix\Database\Providers\DatabaseContextProvider;
use Phoenix\Database\Providers\DatabaseServiceProvider;
use Phoenix\Datastore\Interfaces\DataModel;
use Phoenix\Datastore\Interfaces\Datastore;
use Phoenix\Utils\Helpers\Arr;

class DatabaseDatastoreHandler implements Datastore
{
    protected DatabaseServiceProvider $serviceProvider;
    protected DatabaseContextProvider $contextProvider;

    public function __construct(
        DatabaseServiceProvider $serviceProvider,
        DatabaseContextProvider $contextProvider
    )
    {
        $this->serviceProvider = $serviceProvider;
        $this->contextProvider = $contextProvider;
    }

    public function find($id): DataModel
    {
        return $this->serviceProvider->cacheableService->getWithCache(
            Operation::Read,
            $this->getCacheContextForItem($id),
            fn() => $this->contextProvider->modelAdapter->toModel(
                $this->serviceProvider->queryStrategy->query(
                    $this->serviceProvider->queryBuilder
                        ->select('*')
                        ->from($this->contextProvider->table)
                        ->where('id', '=', $id)
                        ->limit(1)
                )
            )
        );
    }

    public function where(array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        $this->serviceProvider->queryBuilder
            ->select('id')
            ->from($this->contextProvider->table);


        if ($limit) {
            $this->serviceProvider->queryBuilder->limit($limit);
        }

        if ($offset) {
            $this->serviceProvider->queryBuilder->offset($offset);
        }

        $this->buildConditions($conditions);

        //TODO: Finish where() method.
    }

    public function findBy(string $field, $value): DataModel
    {
        // TODO: Implement findBy() method.
    }

    public function create(array $attributes): int
    {
        // TODO: Implement create() method.
    }

    public function update($id, array $attributes): void
    {
        // TODO: Implement update() method.
    }

    public function delete($id): void
    {
        // TODO: Implement delete() method.
    }

    public function deleteWhere(array $conditions): void
    {
        // TODO: Implement deleteWhere() method.
    }

    public function count(array $conditions = []): int
    {
        // TODO: Implement count() method.
    }

    public function findIds(array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        // TODO: Implement findIds() method.
    }

    /**
     * Takes the given array of conditions and adds it to the query builder as a where statement.
     *
     * @param array $conditions
     * @return void
     */
    protected function buildConditions(array $conditions)
    {
        $firstCondition = array_shift($conditions);
        $column = Arr::get($firstCondition, 'column');
        $operator = Arr::get($firstCondition, 'operator');
        $value = Arr::get($firstCondition, 'value');

        $this->serviceProvider->queryBuilder->where($column, $operator, $value);

        foreach ($conditions as $condition) {
            $column = Arr::get($condition, 'column');
            $operator = Arr::get($condition, 'operator');
            $value = Arr::get($condition, 'value');

            $this->serviceProvider->queryBuilder->andWhere($column, $operator, $value);
        }
    }

    /**
     * Gets the cache context for the given ID.
     *
     * @param int $id
     * @return array
     */
    protected function getCacheContextForItem(int $id): array
    {
        return ['id' => $id, 'type' => $this->contextProvider->model];
    }
}