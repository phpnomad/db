<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Cache\Interfaces\CachePolicy;

/**
 * Deliberately order-sensitive key function: tests using it prove the
 * datastore's contexts are canonical by construction rather than rescued by
 * a normalizing policy.
 */
class SerializingCachePolicy implements CachePolicy
{
    public function shouldCache(string $operation, array $context = []): bool
    {
        return true;
    }

    public function getCacheKey(array $context): string
    {
        return md5(serialize($context));
    }

    public function getTtl(array $context = []): ?int
    {
        return 60;
    }

    public function shouldInvalidate(string $operation, array $context = []): bool
    {
        return true;
    }
}
