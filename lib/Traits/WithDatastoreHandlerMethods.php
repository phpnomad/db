<?php

namespace PHPNomad\Database\Traits;

use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Core\Exceptions\ItemNotFound;
use PHPNomad\Core\Facades\Event;
use PHPNomad\Database\Events\RecordCreated;
use PHPNomad\Database\Events\RecordDeleted;
use PHPNomad\Database\Events\RecordUpdated;
use PHPNomad\Database\Exceptions\RecordNotFoundException;
use PHPNomad\Database\Interfaces\DatabaseContextProvider;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\DuplicateEntryException;
use PHPNomad\Datastore\Interfaces\CanIdentify;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\HasSingleIntIdentity;
use PHPNomad\Logger\Traits\CanLogException;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Helpers\Obj;

trait WithDatastoreHandlerMethods
{
    protected DatabaseServiceProvider $serviceProvider;
    protected Table $table;
    protected TableSchemaService $tableSchemaService;

    /**
     * @var class-string<DataModel>
     */
    protected string $model;
    protected ModelAdapter $modelAdapter;


    /** @inheritDoc */
    public function andWhere(array $conditions, ?int $limit = null, ?int $offset = null, ?string $orderBy = null, string $order = 'ASC'): array
    {
        $this->buildAndConditions($conditions, $limit, $offset, $orderBy, $order);
        $ids = $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);

        return $this->getModels($ids);
    }

    /** @inheritDoc */
    public function orWhere(array $conditions, ?int $limit = null, ?int $offset = null, ?string $orderBy = null, string $order = 'ASC'): array
    {
        $this->buildOrConditions($conditions, $limit, $offset, $orderBy, $order);

        $ids = $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);

        return $this->getModels($ids);
    }

    public function countAndWhere(array $conditions): int
    {
        $this->buildAndConditions($conditions);

        $this->serviceProvider->queryBuilder->count('*', 'count');

        $results = $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);

        $result = (array) Arr::first($results);

        return Arr::get($result, 'count', 0);
    }

    public function countOrWhere(array $conditions): int
    {
        $this->buildOrConditions($conditions);

        $this->serviceProvider->queryBuilder->count('*', 'count');

        $results = $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);

        $result = (array) Arr::first($results);

        return Arr::get($result, 'count', 0);
    }


    public function findBy(string $field, $value): DataModel
    {
        return Arr::get($this->andWhere([['column' => $field, 'operator' => '=', 'value' => $value]], 1), 0);
    }

    /** @inheritDoc */
    public function create(array $attributes): DataModel
    {
        $fields = $this->table->getFieldsForIdentity();

        if (Obj::implements($this->model, HasSingleIntIdentity::class)) {
            $attributes = $this->removeIdentifiableFields($attributes, $fields);
        } else {
            $this->maybeThrowForDuplicateIdentity($attributes, $fields);
        }

        $this->maybeThrowForDuplicateUniqueFields($attributes);

        $ids = $this->serviceProvider->queryStrategy->insert($this->table, $attributes);

        $result = Arr::get($this->getModels([$ids]), 0);

        Event::broadcast(new RecordCreated($result));

        return $result;
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
        $items = $this->andWhere($conditions);

        foreach ($items as $item) {
            $identity = $item->getIdentity();

            $this->serviceProvider->queryStrategy->delete($this->table, $identity);
            $this->serviceProvider->cacheableService->delete($this->getCacheContextForItem($identity));

            Event::broadcast(new RecordDeleted($this->model, $identity));
        }
    }

    /**
     * Takes the given array of conditions and adds it to the query builder as a where statement.
     *
     * @param array $conditions
     * @param string $whereType
     * @return void
     */
    protected function buildConditions(array $conditions, string $whereType = 'and')
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

            if ($whereType === 'and') {
                $this->serviceProvider->queryBuilder->andWhere($column, $operator, $value);
            } else {
                $this->serviceProvider->queryBuilder->orWhere($column, $operator, $value);
            }
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
            ->from($this->table)
            ->select(...$this->table->getFieldsForIdentity());


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
                $data = $this->serviceProvider->queryStrategy->query(
                    $this->serviceProvider->queryBuilder
                        ->from($this->table)
                        ->select('*')
                        ->compoundWhere($this->table->getFieldsForIdentity(), ...$idsToQuery)
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
     * @param non-empty-array<string, int> $ids
     * @return mixed
     * @throws DatastoreErrorException
     * @throws RecordNotFoundException
     */
    protected function findFromCompound(array $ids)
    {
        if (empty($ids)) {
            throw new RecordNotFoundException('Record cannot be found, no IDs provided.');
        }

        return $this->serviceProvider->cacheableService->getWithCache(
            Operation::Read,
            $this->getCacheContextForItem($ids),
            function () use ($ids) {
                $items = $this->serviceProvider->queryStrategy->query(
                    $this->serviceProvider->queryBuilder
                        ->select('*')
                        ->from($this->table)
                        ->compoundWhere($this->table->getFieldsForIdentity(), $ids)
                        ->limit(1)
                );

                $item = Arr::get($items, 0);

                if (!$item) {
                    throw new RecordNotFoundException('Record not found using the provided identity');
                }

                return $this->modelAdapter->toModel($item);
            }
        );
    }

    /** @inheritDoc */
    public function updateCompound($ids, array $attributes): void
    {
        $this->findFromCompound($ids);
        $this->maybeThrowForDuplicateUniqueFields($attributes, $ids);

        $this->serviceProvider->queryStrategy->update($this->table, $ids, $attributes);
        $this->serviceProvider->cacheableService->delete($this->getCacheContextForItem($ids));

        Event::broadcast(new RecordUpdated($this->model, $ids, $attributes));
    }

    /**
     * @param array $attributes
     * @param array $fields
     * @return void
     * @throws DatastoreErrorException
     * @throws DuplicateEntryException
     */
    protected function maybeThrowForDuplicateIdentity(array $attributes, array $fields): void
    {
        $ids = [];

        foreach ($attributes as $fieldName => $value) {
            if (in_array($fieldName, $fields)) {
                $ids[$fieldName] = $value;
            }
        }

        // Validate item does not already exist.
        if (count($ids) === count($fields)) {
            try {
                $this->findFromCompound($ids);

                $identity = Arr::process($ids)
                    ->map(fn($item, $key) => $item . ' => ' . $key)
                    ->setSeparator(',')
                    ->toString();

                throw new DuplicateEntryException('The specified item identified as ' . $identity . ' already exists.');
            } catch (RecordNotFoundException $e) {
                //continue
            }
        }
    }

    /**
     * @param array $attributes
     * @param array $fields
     * @return array
     */
    protected function removeIdentifiableFields(array $attributes, array $fields): array
    {
        return Arr::filter($attributes, fn($value, $fieldName) => !in_array($fieldName, $fields));
    }

    /**
     * Looks up records that already have records in the specified columns.
     *
     * @param array $data
     * @return DataModel[] list of existing items.
     * @throws DatastoreErrorException
     * @throws RecordNotFoundException
     */
    protected function getDuplicates(array $data): array
    {
        $uniqueColumns = $this->tableSchemaService->getUniqueColumns($this->table);
        $where = [];

        foreach ($uniqueColumns as $uniqueColumn) {
            $search = $data[$uniqueColumn->getName()];
            if (isset($search)) {
                $where[] = ['column' => $uniqueColumn->getName(), 'operator' => '=', 'value' => $search];
            }
        }

        if (empty($where)) {
            return [];
        }

        return $this->orWhere($where);
    }

    /**
     * @param array $data
     * @param array|null $updateIdentity
     * @return void
     * @throws DuplicateEntryException
     * @throws DatastoreErrorException
     */
    protected function maybeThrowForDuplicateUniqueFields(array $data, ?array $updateIdentity = null): void
    {
        try {
            $duplicates = $this->getDuplicates($data);

            // If an identity is provided, filter out items that have the provided identity.
            if (!is_null($updateIdentity)) {
                $duplicates = Arr::filter(
                    $duplicates,
                    fn(CanIdentify $existingItem) => !Arr::containsSameData($existingItem->getIdentity(), $updateIdentity)
                );
            }
        } catch (RecordNotFoundException $e) {
            // Bail if no records were found.
            return;
        }

        if (!empty($duplicates)) {
            throw new DuplicateEntryException('Database operation stopped early because duplicate entries were detected.');
        }
    }

    protected function buildOrConditions(array $conditions, ?int $limit = null, ?int $offset = null, ?string $orderBy = null, string $order = 'ASC'): void
    {
        $this->serviceProvider->queryBuilder
            ->from($this->table)
            ->select(...$this->table->getFieldsForIdentity());

        if ($limit) {
            $this->serviceProvider->queryBuilder->limit($limit);
        }

        if ($offset) {
            $this->serviceProvider->queryBuilder->offset($offset);
        }

        if($orderBy){
            $this->serviceProvider->queryBuilder->orderBy($orderBy, $order);
        }

        if (!empty($conditions)) {
            $this->buildConditions($conditions, 'or');
        }
    }

    protected function buildAndConditions(array $conditions, ?int $limit = null, ?int $offset = null, ?string $orderBy = null, string $order = 'ASC'): void
    {
        $this->serviceProvider->queryBuilder
            ->from($this->table)
            ->select(...$this->table->getFieldsForIdentity());

        if ($limit) {
            $this->serviceProvider->queryBuilder->limit($limit);
        }

        if ($offset) {
            $this->serviceProvider->queryBuilder->offset($offset);
        }

        if($orderBy){
            $this->serviceProvider->queryBuilder->orderBy($orderBy, $order);
        }

        if (!empty($conditions)) {
            $this->buildConditions($conditions);
        }
    }
}