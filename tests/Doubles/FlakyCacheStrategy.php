<?php

namespace PHPNomad\Database\Tests\Doubles;

use RuntimeException;

/**
 * ArrayCacheStrategy whose WRITES (set/delete) and/or READS (get/exists)
 * can be switched to throw, simulating a cache backend dying mid-operation.
 * Tests prime state healthy, flip a switch, and prove the datastore paths
 * degrade instead of breaking.
 */
class FlakyCacheStrategy extends ArrayCacheStrategy
{
    public bool $failWrites = false;
    public bool $failReads = false;

    public function get(string $key)
    {
        if ($this->failReads) {
            throw new RuntimeException('Simulated cache read failure.');
        }

        return parent::get($key);
    }

    public function exists(string $key): bool
    {
        if ($this->failReads) {
            throw new RuntimeException('Simulated cache read failure.');
        }

        return parent::exists($key);
    }

    public function set(string $key, $value, ?int $ttl): void
    {
        if ($this->failWrites) {
            throw new RuntimeException('Simulated cache write failure.');
        }

        parent::set($key, $value, $ttl);
    }

    public function delete(string $key): void
    {
        if ($this->failWrites) {
            throw new RuntimeException('Simulated cache write failure.');
        }

        parent::delete($key);
    }
}
