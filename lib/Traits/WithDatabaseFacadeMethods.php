<?php

namespace PHPNomad\Database\Traits;

use PHPNomad\Database\Abstracts\DatabaseRepository;
use PHPNomad\Database\Exceptions\RecordNotFoundException;
use PHPNomad\Datastore\Interfaces\DataModel;

/**
 * @template TModel of DataModel
 * @method static instance()
 * @method DatabaseRepository getContainedInstance()
 */
trait WithDatabaseFacadeMethods
{
    /**
     * @param int $id
     *
     * @return DataModel
     * @throws RecordNotFoundException
     */
    public static function getById(int $id) : DataModel
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
}