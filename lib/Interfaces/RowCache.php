<?php

namespace PHPNomad\Database\Interfaces;

/**
 * The cache-context contract for one table's datastore: canonical row
 * identities, alias entries, set-level contexts, and per-table generation
 * tokens. Every cache key a datastore reads or invalidates is built (and
 * every mutation performed) behind this contract, so writers can always name
 * the keys readers used.
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
     * Extracts the canonical identity from row data, or null (logged) when
     * the row is missing an identity field.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null Scalars stringified, table order.
     */
    public function rowIdentity(array $row): ?array;

    /**
     * Builds the ONE cache context a row is stored under.
     *
     * @param array<string, mixed> $row
     * @param string|null $generation Generation snapshot; taken fresh when omitted.
     */
    public function rowContext(array $row, ?string $generation = null): ?array;

    /**
     * Wraps an already-canonical identity in the row cache context.
     *
     * @param array<string, mixed> $identity
     * @param string|null $generation Generation snapshot; taken fresh when omitted.
     */
    public function identityContext(array $identity, ?string $generation = null): array;

    /**
     * Builds the cache context for an alias entry (business-key lookup →
     * canonical identity pointer).
     *
     * @param array<string, mixed> $ids
     * @param string|null $generation Generation snapshot; taken fresh when omitted.
     */
    public function aliasContext(array $ids, ?string $generation = null): array;

    /**
     * The set-level context for whole-table values (estimatedCount and any
     * future query caches).
     *
     * @param string|null $generation Generation snapshot; taken fresh when omitted.
     */
    public function tableContext(?string $generation = null): array;

    /**
     * Reads the identity an alias entry points at, validated against the
     * table's identity shape. Null on miss, cache failure, or malformed value.
     *
     * @param array<string, mixed> $ids
     * @param string|null $generation
     * @return array<string, mixed>|null
     */
    public function resolveAliasedIdentity(array $ids, ?string $generation = null): ?array;

    /**
     * Stores a row's model under its canonical row context. Skips (logged)
     * when the row cannot produce a full identity.
     *
     * @param array<string, mixed> $row
     * @param mixed $model
     * @param string|null $generation Pre-query generation snapshot — see the
     *                                implementation for why read paths must
     *                                pass the snapshot they queried under.
     */
    public function storeRow(array $row, $model, ?string $generation = null): void;

    /**
     * Stores an alias entry pointing a business key at a canonical identity.
     *
     * @param array<string, mixed> $ids
     * @param array<string, mixed> $identity
     * @param string|null $generation
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
     * @param string|null $generation
     */
    public function deleteAlias(array $ids, ?string $generation = null): void;

    /**
     * Deletes the set-level context. Used by writes on generation-disabled
     * tables, where no bump exists to orphan it.
     */
    public function deleteTableContext(): void;

    /**
     * Folds the current table generation into a cache context.
     */
    public function withGeneration(array $context, ?string $generation = null): array;

    /**
     * Takes the generation snapshot an operation should key its contexts
     * under — once, before any database query. Null when generations are
     * disabled.
     */
    public function snapshotGeneration(): ?string;

    /**
     * Replaces the table's generation token after a successful write.
     *
     * @return string|null The fresh token, or null when generations are disabled.
     */
    public function bumpGeneration(): ?string;

    /**
     * Whether contexts built by this service carry a generation token.
     */
    public function usesGenerations(): bool;
}
