<?php

namespace PHPNomad\Database\Services;

use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\RowCache;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use Throwable;

/**
 * Owns the cache-context vocabulary for one table's datastore: canonical row
 * identities, alias entries, and per-table generation tokens.
 *
 * The design invariant this class enforces: a row has exactly ONE cache
 * entry, keyed by its table identity derived from row data — never from the
 * model's own identity, never from the shape of a caller's lookup. Every
 * row, alias, and set-level context is built (and every cache mutation on
 * them performed) here, so writers can always name the keys readers used —
 * which is what makes precise invalidation possible at all.
 *
 * @see \PHPNomad\Database\Traits\WithDatastoreHandlerMethods the consuming datastore flows
 */
class DatastoreRowCache implements RowCache
{
    protected CacheableService $cacheableService;
    protected LoggerStrategy $loggerStrategy;
    protected Table $table;

    /**
     * @var class-string<DataModel>
     */
    protected string $model;
    protected ModelAdapter $modelAdapter;
    protected bool $useGenerations;

    /**
     * @param class-string<DataModel> $model
     */
    public function __construct(
        CacheableService $cacheableService,
        LoggerStrategy $loggerStrategy,
        Table $table,
        string $model,
        ModelAdapter $modelAdapter,
        bool $useGenerations = true
    ) {
        $this->cacheableService = $cacheableService;
        $this->loggerStrategy = $loggerStrategy;
        $this->table = $table;
        $this->model = $model;
        $this->modelAdapter = $modelAdapter;
        $this->useGenerations = $useGenerations;
    }

    /** @inheritDoc */
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
                $this->loggerStrategy->warning(
                    'Cannot derive a canonical cache identity — row is missing an identity field.',
                    ['table' => $this->table->getName(), 'missingField' => $field, 'rowFields' => array_keys($row)]
                );

                return null;
            }

            $identity[$field] = $row[$field];
        }

        return $this->stringifyScalars($identity);
    }

    /** @inheritDoc */
    public function identityKey(array $row): ?string
    {
        $identity = $this->rowIdentity($row);

        return $identity === null ? null : serialize($identity);
    }

    /**
     * Builds the ONE cache context a row is stored under, derived from row
     * data rather than from whatever shape the caller asked for.
     *
     * @param array<string, mixed> $row
     * @param string|null $generation Generation snapshot to key under; taken fresh when omitted.
     * @return array|null Null when the row cannot produce a full identity.
     */
    protected function rowContext(array $row, ?string $generation = null): ?array
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
    protected function identityContext(array $identity, ?string $generation = null): array
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
    protected function aliasContext(array $ids, ?string $generation = null): array
    {
        $normalized = $this->stringifyScalars($ids);

        ksort($normalized);

        return $this->withGeneration(['type' => $this->model, 'alias' => $normalized], $generation);
    }

    /**
     * The set-level context — one undiscriminated slot per table.
     *
     * @param string|null $generation Generation snapshot; taken fresh when omitted.
     */
    protected function tableContext(?string $generation = null): array
    {
        return $this->withGeneration(['type' => $this->model], $generation);
    }

    /** @inheritDoc */
    public function storeRow(array $row, $model, ?string $generation = null): void
    {
        if ($this->isEphemeralGeneration($generation)) {
            return;
        }

        $context = $this->rowContext($row, $generation);

        if ($context === null) {
            return;
        }

        try {
            $this->cacheableService->set($context, $model);
        } catch (Throwable $e) {
            $this->loggerStrategy->warning(
                'Could not cache a row — the next read will hit the database.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
            );
        }
    }

    /** @inheritDoc */
    public function storeAlias(array $ids, array $identity, ?string $generation = null): void
    {
        if ($this->isEphemeralGeneration($generation)) {
            return;
        }

        try {
            $this->cacheableService->set($this->aliasContext($ids, $generation), $identity);
        } catch (Throwable $e) {
            $this->loggerStrategy->warning(
                'Could not cache an alias — the next lookup will re-resolve from the database.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
            );
        }
    }

    /** @inheritDoc */
    public function deleteRow(array $identity, ?string $generation = null): void
    {
        try {
            $this->cacheableService->delete($this->identityContext($identity, $generation));
        } catch (Throwable $e) {
            $this->loggerStrategy->error(
                'Could not delete a cached row — it may serve stale data until the generation bump or TTL.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
            );
        }
    }

    /** @inheritDoc */
    public function deleteAlias(array $ids, ?string $generation = null): void
    {
        try {
            $this->cacheableService->delete($this->aliasContext($ids, $generation));
        } catch (Throwable $e) {
            $this->loggerStrategy->error(
                'Could not delete a cached alias — it may serve a stale identity until the generation bump or TTL.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
            );
        }
    }

    /**
     * Deletes the set-level context. invalidateAfterWrite() calls this for
     * generation-disabled tables, where no bump exists to orphan it.
     */
    protected function deleteTableContext(): void
    {
        $this->cacheableService->delete($this->tableContext());
    }

    /** @inheritDoc */
    public function readRow(array $identity, ?string $generation, callable $fallback)
    {
        return $this->guardedReadThrough($this->identityContext($identity, $generation), $fallback);
    }

    /**
     * Read-through that survives a failing cache backend without masking
     * domain exceptions. Three outcomes are distinguished by where the
     * failure happened relative to the fallback:
     *
     *  - fallback loaded, then the cache store threw → return the loaded
     *    value (the cache write was best-effort);
     *  - the fallback itself threw → propagate untouched (that is a domain
     *    error like RecordNotFoundException, not a cache problem);
     *  - the cache probe threw before the fallback ran → load directly from
     *    the fallback.
     *
     * @param array $context
     * @param callable $fallback
     * @return mixed
     */
    protected function guardedReadThrough(array $context, callable $fallback)
    {
        $started = false;
        $resolved = false;
        $value = null;

        $capturing = function () use ($fallback, &$started, &$resolved, &$value) {
            $started = true;
            $value = $fallback();
            $resolved = true;

            return $value;
        };

        try {
            return $this->cacheableService->getWithCache(Operation::Read, $context, $capturing);
        } catch (Throwable $e) {
            if ($resolved) {
                $this->loggerStrategy->warning(
                    'Cache store failed after a successful load — serving the loaded value uncached.',
                    ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
                );

                return $value;
            }

            if ($started) {
                throw $e;
            }

            $this->loggerStrategy->warning(
                'Cache read failed — loading directly from the fallback.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
            );

            return $fallback();
        }
    }

    /** @inheritDoc */
    public function hasRow(array $identityRow, ?string $generation = null): bool
    {
        $context = $this->rowContext($identityRow, $generation);

        if ($context === null) {
            return false;
        }

        try {
            return $this->cacheableService->exists($context);
        } catch (Throwable $e) {
            // A probe failure reads as uncached — the caller falls through
            // to the database.
            $this->loggerStrategy->warning(
                'Row cache probe failed — treating the row as uncached.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
            );

            return false;
        }
    }

    /**
     * @inheritDoc
     *
     * The table context is a single undiscriminated slot: it holds exactly
     * one set-level value per table (estimatedCount today). A second value
     * would need a discriminator added to the context.
     */
    public function readTableValue(callable $fallback)
    {
        return $this->guardedReadThrough($this->tableContext(), $fallback);
    }

    /**
     * @inheritDoc
     *
     * On generation-enabled tables, fields the adapter does not expose are
     * skipped — the bump already covers rotation, and models with narrower
     * serialization should not lose alias caching over it. On
     * generation-disabled tables ANY unverifiable field fails verification
     * (with a warning): this check is the only rotation defense those tables
     * have, and a field that cannot be checked is exactly the field a
     * rotation may have changed.
     */
    public function matchesLookup(DataModel $model, array $ids): bool
    {
        $data = $this->modelAdapter->toArray($model);

        foreach ($ids as $field => $value) {
            if (!array_key_exists($field, $data)) {
                if ($this->useGenerations) {
                    continue;
                }

                $this->loggerStrategy->warning(
                    'Alias lookup field could not be verified — the model adapter does not expose it; treating the alias as stale.',
                    ['table' => $this->table->getName(), 'field' => $field]
                );

                return false;
            }

            // Normalize both sides through the same rule cache keys use, so
            // this comparison can never drift from key equality.
            [$actual, $expected] = array_values($this->stringifyScalars([
                'actual' => $data[$field],
                'expected' => $value,
            ]));

            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @inheritDoc */
    public function invalidateAfterWrite(): ?string
    {
        try {
            if (!$this->useGenerations) {
                $this->deleteTableContext();

                return null;
            }

            return $this->bumpGeneration();
        } catch (Throwable $e) {
            $this->loggerStrategy->error(
                'Post-write cache invalidation failed — cached rows may serve stale data until TTL.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
            );

            return null;
        }
    }

    /** @inheritDoc */
    public function resolveAliasedIdentity(array $ids, ?string $generation = null): ?array
    {
        try {
            $aliased = $this->cacheableService->get($this->aliasContext($ids, $generation));
        } catch (CachedItemNotFoundException $e) {
            return null;
        } catch (Throwable $e) {
            // A cache-layer failure reads as a miss: every alias caller has
            // a database fallback, so a throwing backend degrades to
            // uncached instead of breaking the lookup.
            $this->loggerStrategy->warning(
                'Alias cache read failed — treating the alias as missing.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
            );

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
    protected function withGeneration(array $context, ?string $generation = null): array
    {
        if (!$this->useGenerations) {
            return $context;
        }

        $context['gen'] = $generation ?? $this->currentGeneration();

        return $context;
    }

    /** @inheritDoc */
    public function snapshotGeneration(): ?string
    {
        return $this->useGenerations ? $this->currentGeneration() : null;
    }

    /**
     * Replaces the table's generation token. Called after every write that
     * committed at least one row. A no-op when generations are disabled for
     * this table.
     *
     * @return string|null The freshly minted token — propagated through
     *                     invalidateAfterWrite() for implementations that add
     *                     post-write cache writes; null when generations are
     *                     disabled.
     */
    protected function bumpGeneration(): ?string
    {
        if (!$this->useGenerations) {
            return null;
        }

        $token = $this->mintGeneration();

        $this->cacheableService->set($this->generationContext(), $token);

        return $token;
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
     *
     * The get-then-set is last-write-wins rather than add-if-absent, and
     * that is safe: every minter SETs its token before performing its DB
     * read, and tokens are random — concurrent re-mints can only orphan
     * each other's fresh entries (a cold-start hit-rate cost), never revive
     * a stale one.
     */
    protected function currentGeneration(): string
    {
        try {
            $token = $this->cacheableService->get($this->generationContext());
        } catch (CachedItemNotFoundException $e) {
            $token = null;
        } catch (Throwable $e) {
            // A read FAILURE (not a miss) mints an ephemeral token for this
            // operation only, WITHOUT persisting it: if reads blip while
            // writes still work, persisting would let every reader clobber
            // a healthy token and wholesale-invalidate the table cache.
            $this->loggerStrategy->warning(
                'Generation token read failed — using an ephemeral token for this operation.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
            );

            return $this->mintEphemeralGeneration();
        }

        if (!is_string($token) || $token === '') {
            $token = $this->mintGeneration();

            try {
                $this->cacheableService->set($this->generationContext(), $token);
            } catch (Throwable $e) {
                // Cache down: every operation mints its own token, so keys
                // never match and reads fall through to the database —
                // caching degrades to disabled instead of breaking reads.
                $this->loggerStrategy->warning(
                    'Could not persist a table generation token — caching is effectively disabled until the cache recovers.',
                    ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
                );
            }
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
     * Mints a token for a single operation during a token-read outage. The
     * prefix marks it so write-backs can skip: entries keyed under a token
     * nobody else can ever read are pure garbage written at read-traffic
     * rate.
     */
    protected function mintEphemeralGeneration(): string
    {
        return 'ephemeral-' . bin2hex(random_bytes(8));
    }

    /**
     * True when the snapshot was minted during a token-read outage and no
     * cache entry keyed under it can ever be served.
     */
    protected function isEphemeralGeneration(?string $generation): bool
    {
        return $generation !== null && strpos($generation, 'ephemeral-') === 0;
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
            if (is_bool($value)) {
                // (string) false is '' — explicit '0' keeps booleans from
                // colliding with empty strings in cache keys.
                $values[$key] = $value ? '1' : '0';
            } elseif (is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return $values;
    }
}
