<?php

namespace PHPNomad\Database\Interfaces;

use PHPNomad\Datastore\Interfaces\DataModel;

/**
 * The cache-context contract for one table's datastore: canonical row
 * identities, alias entries, set-level contexts, and per-table generation
 * tokens. Every cache read and every mutation the datastore performs
 * happens behind this contract — the consuming trait holds no cache access
 * of its own — so writers can always name the keys readers used. The
 * WHEN of alias healing (verify on hit, drop on mismatch or dead target)
 * is datastore orchestration and lives with the consuming trait; this
 * contract supplies the operations it composes.
 *
 * No-throw obligation: consumers call these methods UNGUARDED on read and
 * write paths alike, so cache-layer failures must never escape — each
 * method documents its own degradation (reads generally degrade to misses,
 * mutations swallow-and-log, and snapshotGeneration distinguishes a read
 * FAILURE from a miss). A throwing implementation breaks datastore reads
 * and deletes during a cache outage.
 *
 * @see \PHPNomad\Database\Services\DatastoreRowCache the default implementation
 */
interface RowCache
{
    /**
     * True when the given compound key is exactly the table's identity field
     * set (order-insensitive).
     *
     * @param array<string, mixed> $ids
     */
    public function isTableIdentity(array $ids): bool;

    /**
     * Extracts the canonical identity from row data. Null (logged) when the
     * row is missing an identity field.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null Scalars stringified, table order.
     */
    public function rowIdentity(array $row): ?array;

    /**
     * Opaque equality key for a row's canonical identity — two rows are the
     * same record exactly when their identity keys match. Consumers use this
     * for local dedupe so identity equality stays defined in one place.
     *
     * @param array<string, mixed> $row
     * @return string|null Null when the row cannot produce a full identity.
     */
    public function identityKey(array $row): ?string;

    /**
     * Reads the identity an alias entry points at, validated against the
     * table's identity shape. Null on miss, malformed value, or a
     * cache-layer read failure (logged).
     *
     * @param array<string, mixed> $ids
     * @param string|null $generation Pre-query generation snapshot.
     * @return array<string, mixed>|null
     */
    public function resolveAliasedIdentity(array $ids, ?string $generation = null): ?array;

    /**
     * Read-through for a canonical row: serves the cached model or runs the
     * fallback and caches its result — every row cache read AND the miss-path
     * write happen behind this contract.
     *
     * Failure semantics: cache-layer failures must never surface — serve the
     * fallback's value when only the post-load store failed, and load
     * directly when the probe itself broke. Exceptions thrown BY the
     * fallback are domain errors (RecordNotFoundException) and must
     * propagate untouched. Under an ephemeral generation snapshot the cache
     * is skipped entirely and the fallback serves the read.
     *
     * @param array<string, mixed> $identity Canonical identity (from rowIdentity()).
     * @param string|null $generation Pre-query generation snapshot.
     * @param callable $fallback Loads the model on miss; its result is cached.
     * @return mixed The cached or freshly loaded model.
     */
    public function readRow(array $identity, ?string $generation, callable $fallback);

    /**
     * Whether a row entry exists for the given identity row. False when the
     * row cannot produce a full identity (it can never have been cached).
     *
     * @param array<string, mixed> $identityRow
     * @param string|null $generation Pre-query generation snapshot.
     */
    public function hasRow(array $identityRow, ?string $generation = null): bool;

    /**
     * Read-through for the table's single set-level value: serves the cached
     * value or runs the fallback and caches its result. One undiscriminated
     * slot per table — a second whole-table value would need a discriminator
     * added to the context. Same failure semantics as readRow().
     *
     * @param callable $fallback Computes the value on miss; its result is cached.
     * @return mixed
     */
    public function readTableValue(callable $fallback);

    /**
     * True when the model still carries the caller's lookup values —
     * the guard against an alias whose business key was rotated out from
     * under it by an identity-keyed update. For generation-disabled tables
     * ANY lookup field the adapter cannot expose makes the lookup
     * unverifiable and the alias is treated as stale, because this check is
     * their ONLY rotation defense; generation-enabled tables skip
     * unverifiable fields (the bump covers rotation). Exceptions thrown by
     * the model adapter are domain errors and propagate (the same carve-out
     * readRow() makes for its fallback).
     *
     * @param DataModel $model
     * @param array<string, mixed> $ids The caller's lookup key.
     */
    public function matchesLookup(DataModel $model, array $ids): bool;

    /**
     * The one post-write invalidation call: bumps the generation when
     * generations are on, precisely deletes the set-level context when they
     * are off. Every successful write must end with this.
     *
     * @return string|null The fresh generation token (for post-write cache
     *                     writes), or null when generations are disabled.
     */
    public function invalidateAfterWrite(): ?string;

    /**
     * Stores a row's model under its canonical row context. Skips (logged)
     * when the row cannot produce a full identity.
     *
     * Contract-level invariant for ANY generation-aware implementation: read
     * paths MUST pass the generation snapshot they took before querying the
     * database. A token fetched at store time can postdate a concurrent
     * write's bump, landing a stale row under the new generation — the exact
     * race generations exist to close.
     *
     * Stores MUST no-op under an ephemeral generation snapshot (minted
     * during a token-read outage): such entries can never be read back.
     *
     * @param array<string, mixed> $row
     * @param mixed $model
     * @param string|null $generation Pre-query generation snapshot — REQUIRED
     *                     (null only for generation-disabled tables): a token
     *                     fetched at store time can postdate a concurrent
     *                     write's bump, so the signature forces the caller to
     *                     thread the snapshot it queried under.
     */
    public function storeRow(array $row, $model, ?string $generation): void;

    /**
     * Stores an alias entry pointing a business key at a canonical identity.
     *
     * Stores MUST no-op under an ephemeral generation snapshot.
     *
     * @param array<string, mixed> $ids
     * @param array<string, mixed> $identity
     * @param string|null $generation Pre-query generation snapshot.
     */
    public function storeAlias(array $ids, array $identity, ?string $generation): void;

    /**
     * Deletes the row entry for a canonical identity.
     *
     * @param array<string, mixed> $identity
     * @param string|null $generation Pre-query generation snapshot.
     */
    public function deleteRow(array $identity, ?string $generation): void;

    /**
     * Deletes an alias entry.
     *
     * @param array<string, mixed> $ids
     * @param string|null $generation Pre-query generation snapshot.
     */
    public function deleteAlias(array $ids, ?string $generation): void;

    /**
     * Takes the generation snapshot an operation should key its contexts
     * under — once, before any database query. Null when generations are
     * disabled.
     *
     * Failure is NOT a miss here: a missing token may be minted and
     * persisted, but a token that could not be READ must yield an ephemeral
     * token that is never persisted — persisting on a read blip would let
     * every reader clobber a healthy token and wholesale-invalidate the
     * table cache.
     */
    public function snapshotGeneration(): ?string;
}
