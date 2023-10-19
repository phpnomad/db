<?php

namespace Phoenix\Database\Abstracts;

use Phoenix\Database\Events\RecordCreated;
use Phoenix\Database\Events\RecordDeleted;
use Phoenix\Database\Events\RecordUpdated;
use Phoenix\Database\Exceptions\DatabaseErrorException;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Interfaces\ModelAdapter;
use Phoenix\Database\Interfaces\QueryBuilder;
use Phoenix\Database\Interfaces\QueryStrategy;
use Phoenix\Database\Interfaces\Table;
use Phoenix\Database\Providers\DatabaseCacheProvider;
use Phoenix\Database\Services\CacheableQueryService;
use Phoenix\Events\Interfaces\EventStrategy;
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
    protected EventStrategy $eventStrategy;

    public function __construct(
        Table                 $table,
        ModelAdapter          $modelAdapter,
        QueryStrategy         $queryStrategy,
        DatabaseCacheProvider $cacheProvider,
        CacheableQueryService $cacheableQueryService,
        LoggerStrategy        $loggerStrategy,
        EventStrategy         $eventStrategy,
        QueryBuilder          $queryBuilder
    )
    {
        $this->table = $table;
        $this->modelAdapter = $modelAdapter;
        $this->queryStrategy = $queryStrategy;
        $this->queryBuilder = $queryBuilder;
        $this->cacheableQueryService = (clone $cacheableQueryService);
        $this->cacheProvider = (clone $cacheProvider)->useTable($this->table);
        $this->loggerStrategy = $loggerStrategy;
        $this->eventStrategy = $eventStrategy;
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
            $record = $this->cacheProvider->load($id, fn() => $this->modelAdapter->toModel(
                $this->queryStrategy->find($this->table, $id)
            ));

            return $record;
        } catch (RecordNotFoundException $e) {
            throw $e;
        } catch (DatabaseErrorException $e) {
            $this->loggerStrategy->logException($e, 'Could not get by ID');
        }
    }

    /**
     * Sets up the query interface for this table.
     *
     * @return CacheableQueryService
     */
    public function query(): CacheableQueryService
    {
        return (clone $this->cacheableQueryService)
            ->limit(10)
            ->useTable($this->table)
            ->useModelAdapter($this->modelAdapter);
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
            $this->eventStrategy->broadcast(new RecordDeleted($this->table, $id));
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

        $id = $this->queryStrategy->create($this->table, $data);
        $this->eventStrategy->broadcast(new RecordCreated($this->table, $data, $id));

        return $id;
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
        $this->eventStrategy->broadcast(new RecordUpdated($this->table, $data, $id));
    }
}
