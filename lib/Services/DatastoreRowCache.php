<?php

namespace PHPNomad\Database\Services;

use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

/**
 * Owns the cache-context vocabulary for one table's datastore: canonical row
 * identities, alias entries, and per-table generation tokens.
 *
 * The design invariant this class enforces: a row has exactly ONE cache
 * entry, keyed by its table identity derived from row data — never from the
 * model's own identity, never from the shape of a caller's lookup. Because
 * every context is built here, writers can always name the keys readers
 * used, which is what makes precise invalidation possible at all.
 *
 * @see \PHPNomad\Database\Traits\WithDatastoreHandlerMethods the consuming datastore flows
 */
class DatastoreRowCache
{
    protected CacheableService $cacheableService;
    protected LoggerStrategy $logger;
    protected Table $table;

    /**
     * @var class-string
     */
    protected string $model;
    protected bool $useGenerations;

    /**
     * @param class-string $model
     */
    public function __construct(
        CacheableService $cacheableService,
        LoggerStrategy $logger,
        Table $table,
        string $model,
        bool $useGenerations = true
    ) {
        $this->cacheableService = $cacheableService;
        $this->logger = $logger;
        $this->table = $table;
        $this->model = $model;
        $this->useGenerations = $useGenerations;
    }

    /**
     * True when the given compound key is exactly the table's identity field
     * set (order-insensitive).
     *
     * @param array<string, mixed> $ids
     */
    public function isTableIdentity(array $ids): bool
    {
        $identityFields = $this->table->getFieldsForIdentity();

        return !empty($identityFields)
            && count($ids) === count($identityFields)
            && !array_diff(array_keys($ids), $identityFields);
    }

    /**
     * Extracts the canonical identity from row data: the table's identity
     * fields, in the table's declared order, with scalar values normalized to
     * strings (an int identity from a hydrated write and a string identity
     * from the query strategy must be the same identity).
     *
     * Returns null (and logs) when the row is missing an identity field —
     * a partial context must never be used as a cache key.
     *
     * @param array<string, mixed> $row Row data (a DB row, an identity row, or write attributes merged with insert ids).
     * @return array<string, mixed>|null The canonical identity (scalars stringified, table order), or null.
     */
    public function rowIdentity(array $row): ?array
    {
        $identityFields = $this->table->getFieldsForIdentity();

        if (empty($identityFields)) {
            return null;
        }

        $identity = [];

        foreach ($identityFields as $field) {
            if (!array_key_exists($field, $row)) {
                $this->logger->warning(
                    'Cannot derive a canonical cache identity — row is missing an identity field.',
                    ['table' => $this->table->getName(), 'missingField' => $field, 'rowFields' => array_keys($row)]
                );

                return null;
            }

            $identity[$field] = $row[$field];
        }

        return $this->stringifyScalars($identity);
    }

    /**
     * Builds the ONE cache context a row is stored under, derived from row
     * data rather than from whatever shape the caller asked for.
     *
     * @param array<string, mixed> $row
     * @param string|null $generation Generation snapshot to key under; taken fresh when omitted.
     * @return array|null Null when the row cannot produce a full identity.
     */
    public function rowContext(array $row, ?string $generation = null): ?array
    {
        $identity = $this->rowIdentity($row);

        return $identity === null ? null : $this->identityContext($identity, $generation);
    }

    /**
     * Wraps an already-canonical identity (from rowIdentity()) in the row
     * cache context.
     *
     * @param array<string, mixed> $identity
     * @param string|null $generation Generation snapshot to key under; taken fresh when omitted.
     */
    public function identityContext(array $identity, ?string $generation = null): array
    {
        return $this->withGeneration(['type' => $this->model, 'identities' => $identity], $generation);
    }

    /**
     * Builds the cache context for an alias entry: a pointer from a
     * business-key lookup (any compound key that is not the table identity)
     * to the row's canonical identity. Aliases store identities, never row
     * data, so a stale alias self-heals: the row read it points to misses and
     * falls through to the database.
     *
     * @param array<string, mixed> $ids The caller's lookup key.
     * @param string|null $generation Generation snapshot to key under; taken fresh when omitted.
     */
    public function aliasContext(array $ids, ?string $generation = null): array
    {
        $normalized = $this->stringifyScalars($ids);

        ksort($normalized);

        return $this->withGeneration(['type' => $this->model, 'alias' => $normalized], $generation);
    }

    /**
     * Reads the identity an alias entry points at, validated against the
     * table's identity shape. Null on miss, cache failure, or malformed value.
     *
     * @param array<string, mixed> $ids The caller's lookup key.
     * @param string|null $generation Generation snapshot for the alias context.
     * @return array<string, mixed>|null
     */
    public function resolveAliasedIdentity(array $ids, ?string $generation = null): ?array
    {
        try {
            $aliased = $this->cacheableService->get($this->aliasContext($ids, $generation));
        } catch (CachedItemNotFoundException $e) {
            return null;
        }

        return (is_array($aliased) && $this->isTableIdentity($aliased)) ? $aliased : null;
    }

    /**
     * Folds the current table generation into a cache context. The generation
     * is an opaque token every writer replaces, which makes ALL previously
     * written contexts for this table unreachable at once — O(1) table-wide
     * invalidation with no transactions required, and the close for the
     * cache-aside race where a slow reader SETs a stale row back after a
     * writer invalidated it (the stale SET lands under the old generation).
     *
     * @param string|null $generation Snapshot to fold in; fetched fresh when omitted.
     *
     * @see https://developer.wordpress.org/reference/functions/wp_cache_set_last_changed/ the pattern's origin
     */
    public function withGeneration(array $context, ?string $generation = null): array
    {
        if (!$this->useGenerations) {
            return $context;
        }

        $context['gen'] = $generation ?? $this->currentGeneration();

        return $context;
    }

    /**
     * Takes the generation snapshot a read or invalidation operation should
     * key its contexts under — ONCE, at the start of the operation, before
     * any database query. Null when generations are disabled for this table.
     *
     * One snapshot per operation is both the race fence (a stale write-back
     * keyed with a pre-write snapshot can never collide with post-bump
     * reader keys) and the round-trip bound (one token fetch per operation
     * instead of one per row).
     */
    public function snapshotGeneration(): ?string
    {
        return $this->useGenerations ? $this->currentGeneration() : null;
    }

    /**
     * Replaces the table's generation token. Called after every successful
     * write. A no-op when generations are disabled for this table.
     *
     * @return string|null The freshly minted token, so post-write cache
     *                     writes (create()'s pre-warm) can key under it; null
     *                     when generations are disabled.
     */
    public function bumpGeneration(): ?string
    {
        if (!$this->useGenerations) {
            return null;
        }

        $token = $this->mintGeneration();

        $this->cacheableService->set($this->generationContext(), $token);

        return $token;
    }

    /**
     * Whether contexts built by this service carry a generation token.
     */
    public function usesGenerations(): bool
    {
        return $this->useGenerations;
    }

    /**
     * Reads the current generation token for this table, minting one when
     * absent (first read, or after eviction — both simply start a new
     * generation with a cold table cache).
     *
     * Deliberately NOT getWithCache(): a policy whose shouldCache() declines
     * this context would re-mint a token on every read, silently defeating
     * generation stability, and each re-mint would broadcast CacheMissed
     * noise. The token must live outside policy discretion.
     */
    protected function currentGeneration(): string
    {
        try {
            $token = $this->cacheableService->get($this->generationContext());
        } catch (CachedItemNotFoundException $e) {
            $token = null;
        }

        if (!is_string($token) || $token === '') {
            $token = $this->mintGeneration();
            $this->cacheableService->set($this->generationContext(), $token);
        }

        return $token;
    }

    /**
     * The context the generation token itself lives under. Never carries a
     * generation — it IS the generation.
     */
    protected function generationContext(): array
    {
        return ['type' => $this->model, 'generation' => true];
    }

    /**
     * Mints an opaque, unique generation token.
     */
    protected function mintGeneration(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Normalizes scalar values to strings — the single normalization rule
     * every cache key shape shares, so an int identity from a hydrated write
     * and a string identity from the query strategy produce the same key.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed> Same keys; scalar values stringified.
     */
    protected function stringifyScalars(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = is_scalar($value) ? (string) $value : $value;
        }

        return $values;
    }
}
