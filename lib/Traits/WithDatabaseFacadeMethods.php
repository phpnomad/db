<?php

namespace Phoenix\Database\Traits;

use Phoenix\Database\Abstracts\DatabaseRepository;
use Phoenix\Database\Exceptions\RecordNotFoundException;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Services\CacheableQueryService;
use Siren\Collaborators\Facades\Collaborators;

/**
 * @template TModel of DatabaseModel
 * @method static instance()
 * @method DatabaseRepository getContainedInstance()
 */
trait WithDatabaseFacadeMethods
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
        return static::instance()->getContainedInstance()->save($data);
    }

    /**
     * Queries data, leveraging the cache.
     *
     */
    public static function query(): CacheableQueryService
    {
        return static::instance()->getContainedInstance()->query();
    }
}