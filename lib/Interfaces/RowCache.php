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
     * row is missing an identity field, or (not logged) when the table
     * declares no identity fields at all.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null Scalars stringified, table order.
     */
    public function rowIdentity(array $row): ?array;

    /**
     * Reads the identity an alias entry points at, validated against the
     * table's identity shape. Null on miss or malformed value.
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
     * Read-through for the table's set-level value (estimatedCount and any
     * future whole-table caches): serves the cached value or runs the
     * fallback and caches its result.
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
     * unverifiable fields (the bump covers rotation).
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
     * @param array<string, mixed> $row
     * @param mixed $model
     * @param string|null $generation Pre-query generation snapshot.
     */
    public function storeRow(array $row, $model, ?string $generation = null): void;

    /**
     * Stores an alias entry pointing a business key at a canonical identity.
     *
     * @param array<string, mixed> $ids
     * @param array<string, mixed> $identity
     * @param string|null $generation Pre-query generation snapshot.
     */
    public function storeAlias(array $ids, array $identity, ?string $generation = null): void;

    /**
     * Deletes the row entry for a canonical identity.
     *
     * @param array<string, mixed> $identity
     * @param string|null $generation The generation readers wrote under.
     */
    public function deleteRow(array $identity, ?string $generation = null): void;

    /**
     * Deletes an alias entry.
     *
     * @param array<string, mixed> $ids
     * @param string|null $generation Pre-query generation snapshot.
     */
    public function deleteAlias(array $ids, ?string $generation = null): void;

    /**
     * Takes the generation snapshot an operation should key its contexts
     * under — once, before any database query. Null when generations are
     * disabled.
     */
    public function snapshotGeneration(): ?string;

    }
