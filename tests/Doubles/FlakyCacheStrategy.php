<?php

namespace PHPNomad\Database\Tests\Doubles;

use RuntimeException;

/**
 * ArrayCacheStrategy whose WRITES (set/delete) can be switched to throw,
 * simulating a cache backend dying mid-operation. Reads keep working so
 * tests can prime state healthy, flip $failWrites, and prove the write
 * paths degrade instead of breaking.
 */
class FlakyCacheStrategy extends ArrayCacheStrategy
{
    public bool $failWrites = false;

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
