<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
use PHPNomad\Cache\Interfaces\CacheStrategy;

/**
 * Live in-memory cache backend for tests that exercise real cache flows
 * (through a real CacheableService) instead of mocking per-key interactions.
 */
class ArrayCacheStrategy implements CacheStrategy
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key)
    {
        if (!array_key_exists($key, $this->store)) {
            throw new CachedItemNotFoundException('No cached item found for key ' . $key);
        }

        return $this->store[$key];
    }

    public function set(string $key, $value, ?int $ttl): void
    {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function clear(): void
    {
        $this->store = [];
    }
}
