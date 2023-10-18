<?php

namespace Phoenix\Database\Services;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Interfaces\HasUsableTable;
use Phoenix\Database\Interfaces\ModelAdapter;
use Phoenix\Database\Interfaces\Query;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\QueryStrategy;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Database\Mutators\IdsOnly;
use Phoenix\Database\Mutators\Interfaces\QueryMutator;
use Phoenix\Database\Providers\DatabaseCacheProvider;
use Phoenix\Logger\Interfaces\LoggerStrategy;
use Phoenix\Utils\Helpers\Arr;
use Phoenix\Utils\Processors\ListFilter;

class CacheableQueryService implements Query, HasUsableTable
{

    protected QueryStrategy $queryStrategy;
    protected QueryBuilder $queryBuilder;
    protected LoggerStrategy $loggerStrategy;
    protected DatabaseCacheProvider $cacheProvider;
    protected Table $table;
    protected ModelAdapter $modelAdapter;

    public function __construct(
        QueryStrategy  $queryStrategy,
        QueryBuilder   $queryBuilder,
        LoggerStrategy $loggerStrategy,
        DatabaseCacheProvider  $cacheProvider
    )
    {
        $this->queryStrategy = $queryStrategy;
        $this->queryBuilder = $queryBuilder;
        $this->loggerStrategy = $loggerStrategy;
        $this->cacheProvider = clone $cacheProvider;
    }

    /**
     * Sets the table instance.
     *
     * @param Table $table
     *
     * @return $this
     */
    public function useTable(Table $table)
    {
        $this->table = $table;
        $this->cacheProvider->useTable($table);

        return $this;
    }

    /**
     * Sets the model adapter instance.
     *
     * @param ModelAdapter $modelAdapter
     *
     * @return $this
     */
    public function useModelAdapter(ModelAdapter $modelAdapter)
    {
        $this->modelAdapter = $modelAdapter;
        return $this;
    }

    public function query(QueryMutator ...$args): array
    {
        $this->prepareQuery(...$args);
        try {
            // Do the actual query.
            $allIds = $this->queryStrategy->query($this->queryBuilder);

            // In some cases, this should only return IDs.
            if (empty($allIds) || $this->isIdOnlyQuery($args)) {
                return $allIds;
            }
            // Filter out the items that are currently in the cache.
            $idsToQuery = (new ListFilter($allIds))
                ->filterFromCallback('id', function (int $id) {
                    return $this->cacheProvider->exists($id);
                })
                ->filter();
        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e, 'Could not get by ID');
        }

        try {
            // Get the things that aren't in the cache.
            $data = $this->queryStrategy->where($this->table, [['column' => 'id', 'operator' => 'IN', 'value' => [$idsToQuery]]]);
        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e, 'Could not get by ID');
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
        $this->queryBuilder->resetClauses('select')->count('id', 'count');
        try {
            $results = $this->queryStrategy->query($this->queryBuilder);
            $count = Arr::get($results, 'count');

            if (is_null($count)) {
                throw new DatabaseErrorException('Could not find count column in response.');
            }
        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e);
            $count = 0;
        }

        return $count;
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
     * Mutates the query against the list of mutators.
     * @param QueryMutator ...$mutators
     * @return void
     */
    protected function prepareQuery(QueryMutator ...$mutators): void
    {
        $seen = [];

        Arr::process($mutators)
            ->merge($this->table->getQueryDefaults())
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
     * Converts the given dataset into model objects.
     *
     * @param array $data
     * @return DatabaseModel[]
     */
    protected function hydrateItems(array $data): array
    {
        return Arr::map($data, [$this->modelAdapter, 'toModel']);
    }

    /**
     * Caches items in-batch
     *
     * @param DatabaseModel[] $models
     * @return void
     */
    protected function cacheItems(array $models): void
    {
        Arr::map($models, [$this, 'cacheItem']);
    }

}