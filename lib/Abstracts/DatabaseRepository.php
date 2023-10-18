<?php

namespace Phoenix\Database\Abstracts;

use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Interfaces\ModelAdapter;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\QueryStrategy;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Database\Mutators\Interfaces\QueryMutator;
use Phoenix\Database\Providers\DatabaseCacheProvider;
use Phoenix\Database\Services\CacheableQueryService;
use Phoenix\Logger\Interfaces\LoggerStrategy;
use Phoenix\Utils\Helpers\Arr;

/**
 * @template TModel of DatabaseModel
 */
abstract class DatabaseRepository
{
    protected QueryStrategy $queryStrategy;
    protected QueryBuilder $queryBuilder;

    protected ModelAdapter $modelAdapter;
    protected Table $table;
    protected LoggerStrategy $loggerStrategy;
    protected DatabaseCacheProvider $cacheProvider;
    protected CacheableQueryService $cacheableQueryService;

    public function __construct(
        Table                 $table,
        ModelAdapter          $modelAdapter,
        QueryStrategy         $queryStrategy,
        DatabaseCacheProvider $cacheProvider,
        CacheableQueryService $cacheableQueryService,
        LoggerStrategy        $loggerStrategy,
        QueryBuilder          $queryBuilder
    )
    {
        $this->table = $table;
        $this->modelAdapter = $modelAdapter;
        $this->queryStrategy = $queryStrategy;
        $this->queryBuilder = $queryBuilder;

        $this->cacheableQueryService = (clone $cacheableQueryService)
            ->useTable($this->table)
            ->useModelAdapter($this->modelAdapter);

        $this->cacheProvider = (clone $cacheProvider)->useTable($this->table);
        $this->loggerStrategy = $loggerStrategy;
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
            $record = $this->cacheProvider->load($id, function () use ($id) {
                return $this->modelAdapter->toModel(
                    $this
                        ->queryStrategy
                        ->find($this->table, $id)
                );
            });

            return $record;
        } catch (RecordNotFoundException $e) {
            throw $e;
        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e, 'Could not get by ID');
        }
    }

    public function query(QueryMutator ...$args): array
    {
        return $this->cacheableQueryService->query(...$args);
    }

    /**
     * Gets a count of records for the specified query.
     *
     * @param QueryMutator ...$args
     * @return int
     */
    public function count(QueryMutator ...$args): int
    {
        return $this->cacheableQueryService->count(...$args);
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
            $id = Arr::get(
                $this->queryStrategy->findIds($this->table, [['column' => $column, 'operator' => '=', 'value' => $value]], 1),
                0
            );

            if (!$id) {
                throw new RecordNotFoundException('Could not find item with ' . $column . ' value ' . $value);
            }

            return $this->getById(Arr::get($id, 'id'));
        } catch (RecordNotFoundException $e) {
            throw $e;
        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e, 'Could not get by ID');
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
            $this->queryStrategy->delete($this->table, $id);
            $this->cacheProvider->delete($id);
        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e, 'Could not delete record');
        }
    }

    /**
     * @param array $data
     * @return int
     * @throws DatabaseErrorException
     */
    public function create(array $data): int
    {
        // Create cannot set IDs.
        if (isset($data['id'])) {
            unset($data['id']);
        }

        return $this->queryStrategy->create($this->table, $data);
    }

    /**
     * @param int $id
     * @param array $data
     * @return void
     * @throws RecordNotFoundException
     * @throws DatabaseErrorException
     */
    public function update(int $id, array $data): void
    {
        // Strip ID from data, if it's accidentally set.
        if (isset($data['id'])) {
            unset($data['id']);
        }

        $this->queryStrategy->update($this->table, $id, $data);
        $this->cacheProvider->delete($id);
    }
}
