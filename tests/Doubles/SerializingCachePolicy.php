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
    /**
     * @param array<string, mixed> $context
     */
    public function shouldCache(string $operation, array $context = []): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function getCacheKey(array $context): string
    {
        return md5(serialize($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function getTtl(array $context = []): ?int
    {
        return 60;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function shouldInvalidate(string $operation, array $context = []): bool
    {
        return true;
    }
}
