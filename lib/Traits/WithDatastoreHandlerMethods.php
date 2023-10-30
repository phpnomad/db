<?php

namespace PHPNomad\Database\Traits;

use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Core\Exceptions\ItemNotFound;
use PHPNomad\Database\Exceptions\RecordNotFoundException;
use PHPNomad\Database\Interfaces\DatabaseContextProvider;
use PHPNomad\Database\Interfaces\ModelAdapter;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\DuplicateEntryException;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Utils\Helpers\Arr;

trait WithDatastoreHandlerMethods
{
    protected DatabaseServiceProvider $serviceProvider;
    protected Table $table;

    /**
     * @var class-string<DataModel>
     */
    protected string $model;
    protected ModelAdapter $modelAdapter;


    /** @inheritDoc */
    public function where(array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        $this->serviceProvider->queryBuilder
            ->select(...$this->table->getFieldsForIdentity())
            ->from($this->table);

        if ($limit) {
            $this->serviceProvider->queryBuilder->limit($limit);
        }

        if ($offset) {
            $this->serviceProvider->queryBuilder->offset($offset);
        }

        if (!empty($conditions)) {
            $this->buildConditions($conditions);
        }

        $ids = $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);

        return $this->getModels($ids);
    }

    public function findBy(string $field, $value): DataModel
    {
        return Arr::get($this->where([[$field, '=', $value]], 1), 0);
    }

    /** @inheritDoc */
    public function create(array $attributes): DataModel
    {
        $fields = $this->table->getFieldsForIdentity();
        $ids = Arr::process($fields)
            ->flip()
            ->intersect($attributes, $fields)
            ->toArray();

        // Validate item does not already exist.
        if (count($ids) === count($fields)) {
            try {
                $this->findFromCompound($ids);

                $identity = Arr::process($ids)
                    ->map(fn($item, $key) => $item . ' => ' . $key)
                    ->setSeparator(',')
                    ->toString();

                throw new DuplicateEntryException('The specified item identified as ' . $identity . ' already exists.');
            } catch (ItemNotFound $e) {
                //continue
            }
        }

        $ids = $this->serviceProvider->queryStrategy->insert($attributes);

        return Arr::get($this->getModels([$ids]), 0);
    }

    /**
     * Delete all items that fit the specified condition.
     *
     * @param array $conditions
     * @return void
     * @throws DatastoreErrorException
     */
    public function deleteWhere(array $conditions): void
    {
        $items = $this->where($conditions);

        foreach ($items as $item) {
            $identity = $item->getIdentity();
            $this->serviceProvider->queryStrategy->delete($identity);
            $this->serviceProvider->cacheableService->delete($this->getCacheContextForItem($identity));
        }
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
     * @param array<string, int> $identities list of identities keyed by the field name for the identity.
     * @return array
     */
    protected function getCacheContextForItem(array $identities): array
    {
        return ['identities' => Arr::merge($identities), 'type' => $this->model];
    }

    /**
     * Caches items in-batch
     *
     * @param DataModel[] $models
     *
     * @return void
     */
    protected function cacheItems(array $models): void
    {
        Arr::map($models, fn(DataModel $model) => $this->serviceProvider->cacheableService->set(
            $this->getCacheContextForItem($model->getIdentity()),
            $model
        ));
    }

    /**
     * Converts the given dataset into model objects.
     *
     * @param array $data
     *
     * @return DataModel[]
     */
    protected function hydrateItems(array $data): array
    {
        return Arr::map($data, [$this->modelAdapter, 'toModel']);
    }

    /**
     * @param array $conditions
     * @param int|null $limit
     * @param int|null $offset
     * @return array
     * @throws DatastoreErrorException
     */
    public function findIds(array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        $this->serviceProvider->queryBuilder
            ->select(...$this->table->getFieldsForIdentity())
            ->from($this->table);


        if ($limit) {
            $this->serviceProvider->queryBuilder->limit($limit);
        }

        if ($offset) {
            $this->serviceProvider->queryBuilder->offset($offset);
        }

        $this->buildConditions($conditions);

        return $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);
    }

    /**
     * Gets the models from the specified list of IDs.
     *
     * @param array<string, int>[] $ids
     * @return array
     */
    protected function getModels(array $ids): array
    {
        // Filter out the items that are currently in the cache.
        $idsToQuery = Arr::filter(
            $ids,
            fn(array $ids) => !$this->serviceProvider->cacheableService->exists($this->getCacheContextForItem($ids))
        );

        if (!empty($idsToQuery)) {
            try {
                // Get the things that aren't in the cache.
                $data = $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder
                    ->select('*')
                    ->compoundWhere($this->table->getFieldsForIdentity(), ...$idsToQuery)
                    ->from($this->table)
                );
            } catch (DatastoreErrorException $e) {
                $this->serviceProvider->loggerStrategy->logException($e, 'Could not get by ID');
            }

            // Cache those items.
            $this->cacheItems($this->hydrateItems($data));
        }

        // Now, use the cache to get all the posts in the proper order.
        return Arr::map($ids, fn(array $id) => $this->findFromCompound($id));
    }

    /**
     * @param array $ids
     * @return mixed
     * @throws DatastoreErrorException
     * @throws RecordNotFoundException
     */
    protected function findFromCompound(array $ids)
    {
        return $this->serviceProvider->cacheableService->getWithCache(
            Operation::Read,
            $this->getCacheContextForItem($ids),
            fn() => $this->modelAdapter->toModel(
                $this->serviceProvider->queryStrategy->query(
                    $this->serviceProvider->queryBuilder
                        ->select('*')
                        ->from($this->table)
                        ->compoundWhere($this->table->getFieldsForIdentity(), ...$ids)
                        ->limit(1)
                )
            )
        );
    }

    /** @inheritDoc
     */
    public function updateCompound($ids, array $attributes): void
    {
        $this->findFromCompound($ids);

        $this->serviceProvider->queryStrategy->update($ids, $attributes);
        $this->serviceProvider->cacheableService->delete($this->getCacheContextForItem($ids));
    }
}