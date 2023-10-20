<?php

namespace Phoenix\Database\Services;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Interfaces\HasUsableTable;
use Phoenix\Database\Interfaces\ModelAdapter;
use Phoenix\Database\Interfaces\Query;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\QueryStrategy;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Database\Providers\DatabaseCacheProvider;
use Phoenix\Logger\Interfaces\LoggerStrategy;
use Phoenix\Utils\Helpers\Arr;

class CacheableQueryService implements Query, HasUsableTable
{
    protected QueryStrategy $queryStrategy;
    protected QueryBuilder $queryBuilder;
    protected LoggerStrategy $loggerStrategy;
    protected DatabaseCacheProvider $cacheProvider;
    protected Table $table;
    protected ModelAdapter $modelAdapter;

    public function __construct(
        QueryStrategy         $queryStrategy,
        QueryBuilder          $queryBuilder,
        LoggerStrategy        $loggerStrategy,
        DatabaseCacheProvider $cacheProvider
    )
    {
        $this->queryStrategy = $queryStrategy;
        $this->queryBuilder = $queryBuilder;
        $this->loggerStrategy = $loggerStrategy;
        $this->cacheProvider = clone $cacheProvider;
    }

    /** $inheritDoc */
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

    /**
     * Returns the count of records found.
     *
     * @return int
     * @throws DatabaseErrorException
     */
    public function getCount(): int
    {
        $this->queryBuilder->resetClauses('select')->count('id', 'count');

        return Arr::get($this->queryStrategy->query($this->queryBuilder), 'count', 0);
    }

    /**
     * Gets a list of IDs from the query.
     *
     * @throws DatabaseErrorException
     */
    public function getIds(): array
    {
        $this->queryBuilder->resetClauses('select')->select('id');

        return $this->queryStrategy->query($this->queryBuilder);
    }

    /**
     * Gets the models from the specified list of IDs.
     *
     * @param array|null $ids
     * @return array
     */
    public function getModels(?array $ids = null): array
    {
        try {
            $allIds = $ids ?? $this->getIds();

            // Filter out the items that are currently in the cache.
            $idsToQuery = Arr::filter($allIds, fn(int $id) => !$this->cacheProvider->exists($id));

        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e, 'Could not get by ID');
        }

        if(!empty($idsToQuery)) {
            try {
                // Get the things that aren't in the cache.
                $data = $this->queryStrategy->where($this->table, [['column' => 'id', 'operator' => 'IN', 'value' => $idsToQuery]]);
            } catch (DatabaseErrorException $e) {
                $this->loggerStrategy->logException($e, 'Could not get by ID');
            }

            // Cache those items.
            $this->cacheItems($this->hydrateItems($data));
        }

        // Now, use the cache to get all the posts in the proper order.
        return Arr::map($allIds, fn(int $id) => $this->getById($id));
    }

    /**
     * @param int $id
     * @return DatabaseModel
     * @throws RecordNotFoundException
     */
    public function getById(int $id): DatabaseModel
    {
        try {
            return $this->cacheProvider->load($id, fn() => $this->modelAdapter->toModel(
                $this->queryStrategy->find($this->table, $id)
            ));
        } catch (RecordNotFoundException $e) {
            throw $e;
        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e, 'Could not get by ID');
        }
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
        Arr::map($models, fn(DatabaseModel $model) => $this->cacheProvider->set($model));
    }

    /**
     * @see QueryBuilder::where()
     * @param string $field
     * @param string $operand
     * @param $value
     * @param ...$values
     * @return $this
     */
    public function where(string $field, string $operand, $value, ...$values)
    {
        $this->queryBuilder->where($field, $operand, $value, ...$values);

        return $this;
    }

    /**
     * @see QueryBuilder::andWhere()
     * @param string $field
     * @param string $operand
     * @param $value
     * @param ...$values
     * @return $this
     */
    public function andWhere(string $field, string $operand, $value, ...$values)
    {
        $this->andWhere($field, $operand, $value, ...$values);
        return $this;
    }

    /**
     * @see QueryBuilder::orWhere()
     * @param string $field
     * @param string $operand
     * @param $value
     * @param ...$values
     * @return $this
     */
    public function orWhere(string $field, string $operand, $value, ...$values)
    {
        $this->orWhere($field, $operand, $value, ...$values);
        return $this;
    }

    /**
     * @see QueryBuilder::leftJoin()
     * @param Table $table
     * @param string $column
     * @param string $onColumn
     * @return $this
     */
    public function leftJoin(Table $table, string $column, string $onColumn)
    {
        $this->queryBuilder->leftJoin($table, $column, $onColumn);

        return $this;
    }

    /**
     * @see QueryBuilder::rightJoin()
     * @param Table $table
     * @param string $column
     * @param string $onColumn
     * @return $this
     */
    public function rightJoin(Table $table, string $column, string $onColumn)
    {
        $this->queryBuilder->rightJoin($table, $column, $onColumn);

        return $this;
    }

    /**
     * @see QueryBuilder::groupBy()
     * @param string $column
     * @param string ...$columns
     * @return $this
     */
    public function groupBy(string $column, string ...$columns)
    {
        $this->queryBuilder->groupBy($column, ...$columns);

        return $this;
    }

    /**
     * @see QueryBuilder::limit()
     * @param int $limit
     * @return $this
     */
    public function limit(int $limit)
    {
        $this->queryBuilder->limit($limit);

        return $this;
    }

    /**
     * @see QueryBuilder::offset()
     * @param int $offset
     * @return $this
     */
    public function offset(int $offset)
    {
        $this->queryBuilder->offset($offset);

        return $this;
    }

    /**
     * @see QueryBuilder::orderBy()
     * @param string $field
     * @param string $order
     * @return $this
     */
    public function orderBy(string $field, string $order)
    {
        $this->queryBuilder->orderBy($field, $order);

        return $this;
    }

    /**
     * @see QueryBuilder::reset()
     * @return $this
     */
    public function reset()
    {
        $this->queryBuilder->reset();

        return $this;
    }

    /**
     * @see QueryBuilder::resetClauses()
     * @param string $clause
     * @param string ...$clauses
     * @return $this
     */
    public function resetClauses(string $clause, string ...$clauses)
    {
        $this->queryBuilder->resetClauses($clause, ...$clauses);

        return $this;
    }
}