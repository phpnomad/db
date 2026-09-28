<?php

namespace PHPNomad\Database\Services;

use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Cache\Services\CacheableService;
use Throwable;

/**
 * Keeps reads and cache writes inside one coordinated operation.
 *
 * The wrapped service is touched only while publishing confirmed invalidations.
 */
class OperationCacheableService extends CacheableService
{
    private CacheableService $sharedService;

    /** @var array<string, mixed> */
    private array $items = [];

    /** @var array<string, array<string, mixed>> */
    private array $invalidations = [];

    public function __construct(CacheableService $sharedService)
    {
        $this->sharedService = $sharedService;
        parent::__construct(
            $sharedService->eventStrategy,
            $sharedService->cacheStrategy,
            $sharedService->cachePolicy
        );
    }

    /**
     * @param Operation::* $operation
     * @param array<string, mixed> $context
     */
    public function getWithCache(string $operation, array $context, callable $callback): mixed
    {
        $key = $this->cachePolicy->getCacheKey($context);

        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        $value = $callback();
        if ($this->cachePolicy->shouldCache($operation, $context)) {
            $this->items[$key] = $value;
        }

        return $value;
    }

    /** @param array<string, mixed> $context */
    public function get(array $context): mixed
    {
        $key = $this->cachePolicy->getCacheKey($context);

        if (!array_key_exists($key, $this->items)) {
            throw new CachedItemNotFoundException();
        }

        return $this->items[$key];
    }

    /** @param array<string, mixed> $context */
    public function set(array $context, mixed $value): void
    {
        $key = $this->cachePolicy->getCacheKey($context);
        $this->items[$key] = $value;
        $this->invalidations[$key] = $context;
    }

    /** @param array<string, mixed> $context */
    public function delete(array $context): void
    {
        $key = $this->cachePolicy->getCacheKey($context);
        unset($this->items[$key]);
        $this->invalidations[$key] = $context;
    }

    /** @param array<string, mixed> $context */
    public function exists(array $context): bool
    {
        return array_key_exists($this->cachePolicy->getCacheKey($context), $this->items);
    }

    /** @return list<Throwable> */
    public function publishInvalidations(): array
    {
        $failures = [];

        foreach ($this->invalidations as $context) {
            try {
                $this->sharedService->delete($context);
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        $this->invalidations = [];

        return $failures;
    }

    public function discard(): void
    {
        $this->items = [];
        $this->invalidations = [];
    }
}
