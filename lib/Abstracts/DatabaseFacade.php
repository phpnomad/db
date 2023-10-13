<?php

namespace Phoenix\Database\Abstracts;

use Phoenix\Facade\Abstracts\Facade;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Mutators\Interfaces\QueryMutator;

/**
 * @template TModel of DatabaseModel
 * @method DatabaseRepository getContainedInstance()
 */
abstract class DatabaseFacade extends Facade
{
    /**
     * @param int $id
     * @return DatabaseModel
     * @throws RecordNotFoundException
     */
    public static function getById(int $id): DatabaseModel
    {
        return static::instance()->getContainedInstance()->getById($id);
    }

    /**
     * Delete the specified record.
     *
     * @param int $id
     * @return void
     */
    public static function delete(int $id): void
    {
        static::instance()->getContainedInstance()->delete($id);
    }

    /**
     * @param array $data
     * @return void
     * @throws RecordNotFoundException
     */
    public static function save(array $data): int
    {
        static::instance()->getContainedInstance()->save($data);
    }

    /**
     * Queries data, leveraging the cache.
     *
     * @param QueryMutator ...$args List of args used to make this query.
     * @return TModel[]|int[]
     */
    public static function query(QueryMutator ...$args): array
    {
        return static::instance()->getContainedInstance()->query(...$args);
    }

    /**
     * @param QueryMutator ...$args
     * @return int
     */
    public static function count(QueryMutator ...$args): int
    {
        return static::instance()->getContainedInstance()->count(...$args);
    }

    /**
     * @return $this
     */
    abstract public static function instance();
}