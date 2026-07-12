<?php

namespace PHPNomad\Database\Services;

use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\RowCache;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
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
    protected LoggerStrategy $logger;
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
        LoggerStrategy $logger,
        Table $table,
        string $model,
        ModelAdapter $modelAdapter,
        bool $useGenerations = true
    ) {
        $this->cacheableService = $cacheableService;
        $this->logger = $logger;
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

    /**
     * Stores a row's model under its canonical row context. Skips without
     * throwing (rowIdentity() logs the reason) when the row cannot produce a
     * full identity.
     *
     * Read paths MUST pass the generation snapshot they took before querying
     * the database: taking a fresh token here would let a stale row land
     * under a generation minted AFTER a concurrent write — reopening the
     * exact race generations exist to close.
     *
     * @param array<string, mixed> $row
     * @param mixed $model
     * @param string|null $generation Pre-query generation snapshot.
     */
    public function storeRow(array $row, $model, ?string $generation = null): void
    {
        $context = $this->rowContext($row, $generation);

        if ($context !== null) {
            $this->cacheableService->set($context, $model);
        }
    }

    /** @inheritDoc */
    public function storeAlias(array $ids, array $identity, ?string $generation = null): void
    {
        $this->cacheableService->set($this->aliasContext($ids, $generation), $identity);
    }

    /** @inheritDoc */
    public function deleteRow(array $identity, ?string $generation = null): void
    {
        $this->cacheableService->delete($this->identityContext($identity, $generation));
    }

    /** @inheritDoc */
    public function deleteAlias(array $ids, ?string $generation = null): void
    {
        $this->cacheableService->delete($this->aliasContext($ids, $generation));
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
        return $this->cacheableService->getWithCache(
            Operation::Read,
            $this->identityContext($identity, $generation),
            $fallback
        );
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
        return $this->cacheableService->getWithCache(Operation::Read, $this->tableContext(), $fallback);
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

                $this->logger->warning(
                    'Alias lookup field could not be verified — the model adapter does not expose it; treating the alias as stale.',
                    ['table' => $this->table->getName(), 'field' => $field]
                );

                return false;
            }

            $actual = $data[$field];

            if (is_scalar($actual) && is_scalar($value)) {
                if ((string) $actual !== (string) $value) {
                    return false;
                }
            } elseif ($actual !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @inheritDoc */
    public function invalidateAfterWrite(): ?string
    {
        if (!$this->useGenerations) {
            $this->deleteTableContext();

            return null;
        }

        return $this->bumpGeneration();
    }

    /** @inheritDoc */
    public function resolveAliasedIdentity(array $ids, ?string $generation = null): ?array
    {
        try {
            $aliased = $this->cacheableService->get($this->aliasContext($ids, $generation));
        } catch (Throwable $e) {
            // Any cache-layer failure reads as a miss: every alias caller
            // has a database fallback, so a throwing backend degrades to
            // uncached instead of breaking the lookup.
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
     * Replaces the table's generation token. Called after every successful
     * write. A no-op when generations are disabled for this table.
     *
     * @return string|null The freshly minted token, so post-write cache
     *                     writes (create()'s pre-warm) can key under it; null
     *                     when generations are disabled.
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
        } catch (Throwable $e) {
            // Read failure is treated exactly like a missing token: mint a
            // fresh one so the operation proceeds with caching effectively
            // disabled rather than breaking on a dead cache.
            $token = null;
        }

        if (!is_string($token) || $token === '') {
            $token = $this->mintGeneration();

            try {
                $this->cacheableService->set($this->generationContext(), $token);
            } catch (Throwable $e) {
                // Cache down: every operation mints its own token, so keys
                // never match and reads fall through to the database —
                // caching degrades to disabled instead of breaking reads.
                $this->logger->warning(
                    'Could not persist a table generation token — caching is effectively disabled until the cache recovers.',
                    ['table' => $this->table->getName(), 'exception' => $e->getMessage()]
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
