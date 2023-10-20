<?php

namespace Phoenix\Database\Interfaces;

/**
 * @template TModel of DatabaseModel
 */
interface Query
{
    /**
     * Queries data, leveraging the cache.
     *
     * @return TModel[]
     */
    public function getModels(): array;

    /**
     * @return int
     */
    public function getCount(): int;

    /**
     * @return int[]
     */
    public function getIds(): array;
}