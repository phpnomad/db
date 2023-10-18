<?php

namespace Phoenix\Database\Providers;

use Phoenix\Cache\Exceptions\CachedItemNotFoundException;
use Phoenix\Cache\Interfaces\InMemoryCacheStrategy;
use Phoenix\Database\Interfaces\DatabaseModel;
use Phoenix\Database\Interfaces\HasUsableTable;
use Phoenix\Database\Traits\WithUseTable;

class DatabaseCacheProvider implements HasUsableTable
{
    use WithUseTable;

    protected InMemoryCacheStrategy $cacheStrategy;

    public function __construct(
        InMemoryCacheStrategy $cacheStrategy
    )
    {
        $this->cacheStrategy = $cacheStrategy;
    }

    /**
     * Gets the cached item key.
     *
     * @param int $id
     * @return string
     */
    public function getItemCacheKey(int $id): string
    {
        return "{$this->table->getName()}__$id";
    }

    /**
     * Fetches the specified record from the cache.
     *
     * @param int $id
     * @return mixed
     * @throws CachedItemNotFoundException
     */
    public function get(int $id)
    {
        return $this->cacheStrategy->get($this->getItemCacheKey($id));
    }

    /**
     * Sets the sepcified record in the cache.
     *
     * @param int $id
     * @param DatabaseModel $value
     * @return void
     */
    public function set(int $id, DatabaseModel $value): void
    {
         $this->cacheStrategy->set($this->getItemCacheKey($id), $value, $this->table->getCacheTtl());
    }

    /**
     * Deletes the specified record from the cache.
     *
     * @param int $id
     * @return void
     */
    public function delete(int $id): void
    {
         $this->cacheStrategy->delete($this->getItemCacheKey($id));
    }

    /**
     * Fetches the record from the cache or loads it using a callable.
     *
     * @param int $id
     * @param callable $setter
     * @param int|null $ttl
     * @return mixed
     */
    public function load(int $id, callable $setter, ?int $ttl = null)
    {
        return $this->cacheStrategy->load($this->getItemCacheKey($id), $setter, $ttl);
    }

    /**
     * Returns true if the record is cached.
     *
     * @param int $id
     * @return bool
     */
    public function exists(int $id): bool
    {
        return $this->cacheStrategy->exists($this->getItemCacheKey($id));
    }
}