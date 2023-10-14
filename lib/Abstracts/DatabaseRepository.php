<?php

namespace Phoenix\Database\Abstracts;

use Phoenix\Cache\Interfaces\InMemoryCacheStrategy;
use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Interfaces\QueryStrategy;
use Phoenix\Database\Interfaces\ModelAdapter;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Database\Mutators\IdsOnly;
use Phoenix\Database\Mutators\Interfaces\QueryMutator;
use Phoenix\Database\Mutators\Limit;
use Phoenix\Utils\Helpers\Arr;

/**
 * @template TModel of DatabaseModel
 */
abstract class DatabaseRepository
{
    protected QueryStrategy $databaseStrategy;
    protected InMemoryCacheStrategy $cacheStrategy;
    protected QueryBuilder $queryBuilder;

    protected ModelAdapter $modelAdapter;
    protected Table $table;

    public function __construct(
        Table                 $table,
        ModelAdapter          $modelAdapter,
        QueryStrategy         $databaseStrategy,
        InMemoryCacheStrategy $cacheStrategy,
        QueryBuilder          $queryBuilder
    ) {
        $this->databaseStrategy = $databaseStrategy;
        $this->cacheStrategy = $cacheStrategy;
        $this->queryBuilder = $queryBuilder;
        $this->modelAdapter = $modelAdapter;
        $this->table = $table;
    }

    /**
     * @param int $id
     * @return TModel
     * @throws RecordNotFoundException
     */
    public function getById(int $id): DatabaseModel
    {
        try {
            /** @var TModel $record */
            $record = $this->cacheStrategy->load($this->getItemCacheKey($id), function () use ($id) {
                return $this->modelAdapter->toModel(
                    $this
                        ->databaseStrategy
                        ->find($this->table, $id)
                );
            });

            return $record;
        } catch (RecordNotFoundException $e) {
            throw $e;
        } catch (DatabaseErrorException $e) {
            // TODO LOG THIS EXCEPTION
        }
    }

    /**
     * Finds the first available record that has the specified value in the specified column.
     *
     * @param string $column The column to look in
     * @param mixed $value The value to locate.
     * @return TModel
     * @throws RecordNotFoundException
     */
    protected function getBy(string $column, $value): DatabaseModel
    {
        try {
            $id = Arr::pluck(
                $this->databaseStrategy->findIds($this->table, ['column' => $column, 'operator' => '=', $value], 1),
                0
            );

            if (!$id) {
                throw new RecordNotFoundException('Could not find item with ' . $column . ' value ' . $value);
            }

            return $this->getById($id);
        } catch (RecordNotFoundException $e) {
            throw $e;
        } catch (DatabaseErrorException $e) {
            // TODO LOG THIS EXCEPTION
        }
    }

    /**
     * Delete the specified record.
     *
     * @param int $id
     * @return void
     */
    public function delete(int $id): void
    {
        try {
            $this->databaseStrategy->delete($this->table, $id);
            $this->cacheStrategy->delete($this->getItemCacheKey($id));
        } catch (DatabaseErrorException $e) {
            // TODO LOG THIS EXCEPTION
        }
    }

    /**
     * @param array $data
     * @return void
     * @throws RecordNotFoundException
     */
    public function save(array $data): int
    {
        if (isset($data['id'])) {
            $id = $data['id'];
            unset($data['id']);
        }

        try {
            if (isset($id)) {
                $this->databaseStrategy->update($this->table, $id, $data);
                $this->cacheStrategy->delete($this->getItemCacheKey($id));
            } else {
                $id = $this->databaseStrategy->create($this->table, $data);
            }
        } catch (RecordNotFoundException $e) {
            throw $e;
        } catch (DatabaseErrorException $e) {
            // TODO LOG THIS EXCEPTION
        }

        return $id;
    }

    /**
     * Queries data, leveraging the cache.
     *
     * @param QueryMutator ...$args List of args used to make this query.
     * @return TModel[]|int[]
     */
    public function query(QueryMutator ...$args): array
    {
        $this->prepareQuery(...$args);
        try {
            // Do the actual query.
            $allIds = $this->databaseStrategy->query($this->queryBuilder);

            // In some cases, this should only return IDs.
            if (empty($allIds) || $this->isIdOnlyQuery($args)) {
                return $allIds;
            }
        } catch (DatabaseErrorException $e) {
            //TODO: LOG THIS EXCEPTION.
        }

        // Filter out the items that are currently in the cache.
        $idsToQuery = Arr::filter($allIds, function (int $id) {
            $this->cacheStrategy->get($this->getItemCacheKey($id));
        });

        try {
            // Get the things that aren't in the cache.
            $data = $this->databaseStrategy->where($this->table, ['column' => 'id', 'operator' => 'IN', [$idsToQuery]]);
        } catch (DatabaseErrorException $e) {
            //TODO: LOG THIS EXCEPTION.
        }

        // Cache those items.
        $this->cacheItems($this->hydrateItems($data));

        // Now, use the cache to get all the posts in the proper order.
        return Arr::map($allIds, [$this, 'getById']);
    }

    /**
     * @param QueryMutator ...$args
     * @return int
     */
    public function count(QueryMutator ...$args): int
    {
        $this->prepareQuery(...$args);
        $this->queryBuilder->resetClauses('select')->count('*', 'count');
        try {
            $results = $this->databaseStrategy->query($this->queryBuilder);
            $count = Arr::get($results, 'count');

            if (is_null($count)) {
                throw new DatabaseErrorException('Could not find count column in response.');
            }
        } catch (DatabaseErrorException $e) {
            //TODO: LOG THIS EXCEPTION.
        }
    }

    /**
     * Returns true if this query is supposed to only fetch IDs.
     *
     * @param array $args
     * @return bool
     */
    protected function isIdOnlyQuery(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg instanceof IdsOnly) {
                return true;
            }
        }

        return false;
    }

    /**
     * Converts the given dataset into model objects.
     *
     * @param array $data
     * @return TModel[]
     */
    protected function hydrateItems(array $data): array
    {
        return Arr::map($data, [$this->modelAdapter, 'toModel']);
    }

    /**
     * Caches items in-batch
     *
     * @param TModel[] $models
     * @return void
     */
    protected function cacheItems(array $models): void
    {
        Arr::map($models, [$this, 'cacheItem']);
    }

    /**
     * Caches a single item.
     *
     * @param DatabaseModel $model
     * @return void
     */
    protected function cacheItem(DatabaseModel $model): void
    {
        $this->cacheStrategy->set($this->getItemCacheKey($model->getId()), $model, $this->table->getCacheTtl());
    }

    /**
     * Mutates the query against the list of mutators.
     * @param QueryMutator ...$mutators
     * @return void
     */
    protected function prepareQuery(QueryMutator ...$mutators): void
    {
        $seen = [];

        Arr::process($mutators)
            ->merge($mutators, $this->getQueryDefaults())
            ->filter(static function (QueryMutator $mutator) use ($seen) {
                if (!isset($seen[get_class($mutator)])) {
                    $seen[get_class($mutator)] = true;
                    return true;
                }

                return false;
            })
            ->each(function (QueryMutator $mutator) {
                $mutator->mutateQuery($this->queryBuilder);
            });

        // Force the query to only use IDs.
        $this->queryBuilder->resetClauses('select')->select('id');
    }

    /**
     * Gets the default arguments to use for the query() method.
     *
     * @return QueryMutator[]
     */
    protected function getQueryDefaults()
    {
        return [
            new Limit(10)
        ];
    }

    /**
     * Gets the cache key for this item.
     *
     * @param string $id
     * @return string
     */
    protected function getItemCacheKey(string $id): string
    {
        return "{$this->table->getName()}__$id";
    }
}
