<?php

namespace PHPNomad\Database\Adapters;

use InvalidArgumentException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;

/**
 * Pure conversion between datastore data (rows, identities, lookup keys,
 * models) and the cache-context vocabulary the datastore handler reads and
 * invalidates with. No I/O lives here — the datastore handler orchestrates
 * the cache; this adapter only answers "what shape does that live under?"
 *
 * The invariant this vocabulary enforces: a row has exactly ONE cache
 * context, keyed by its table identity derived from row data — never from
 * the model's own identity, never from the shape of a caller's lookup — so
 * writers can always name the keys readers used.
 */
class RowCacheContextAdapter
{
    /**
     * Prefix marking a token minted during a token-read outage. Entries
     * keyed under such a token can never be read back, so every caching
     * path skips them.
     */
    public const EPHEMERAL_GENERATION_PREFIX = 'ephemeral-';

    protected Table $table;

    /**
     * @var class-string<DataModel>
     */
    protected string $model;

    /**
     * @var ModelAdapter<DataModel>
     */
    protected ModelAdapter $modelAdapter;
    protected bool $useGenerations;

    /**
     * @param class-string<DataModel> $model
     * @param ModelAdapter<DataModel> $modelAdapter Used to verify models against lookup values.
     * @param bool $useGenerations Whether contexts carry a per-table generation token.
     */
    public function __construct(Table $table, string $model, ModelAdapter $modelAdapter, bool $useGenerations = true)
    {
        $this->table = $table;
        $this->model = $model;
        $this->modelAdapter = $modelAdapter;
        $this->useGenerations = $useGenerations;
    }

    /**
     * Whether contexts built by this adapter carry a generation token.
     */
    public function usesGenerations(): bool
    {
        return $this->useGenerations;
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

        return count($ids) === count($identityFields)
            && !array_diff(array_keys($ids), $identityFields);
    }

    /**
     * Converts row data to its canonical identity: the table's identity
     * fields, in the table's declared order, with scalar values normalized
     * to strings (an int identity from a hydrated write and a string
     * identity from the query strategy must be the same identity).
     *
     * @param array<string, mixed> $row Row data (a DB row, an identity row, or write attributes merged with insert ids).
     * @return array<string, mixed>|null Null when the row is missing an identity field — a
     *                                   partial context must never become a cache key. (Pure:
     *                                   the caller logs.)
     */
    public function toRowIdentity(array $row): ?array
    {
        $identity = [];

        foreach ($this->table->getFieldsForIdentity() as $field) {
            if (!array_key_exists($field, $row)) {
                return null;
            }

            $identity[$field] = $row[$field];
        }

        return $this->stringifyScalars($identity);
    }

    /**
     * Converts row data to its raw table-identity projection — same fields
     * as toRowIdentity(), original value types. This feeds SQL conditions,
     * where cache-key stringification must not leak into driver-typed
     * comparisons.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public function toRawIdentity(array $row): ?array
    {
        $identity = [];

        foreach ($this->table->getFieldsForIdentity() as $field) {
            if (!array_key_exists($field, $row)) {
                return null;
            }

            $identity[$field] = $row[$field];
        }

        return $identity;
    }

    /**
     * Opaque equality key for a row's canonical identity — two rows are the
     * same record exactly when their identity keys match.
     *
     * @param array<string, mixed> $row
     * @return string|null Null when the row cannot produce a full identity.
     */
    public function toIdentityKey(array $row): ?string
    {
        $identity = $this->toRowIdentity($row);

        return $identity === null ? null : serialize($identity);
    }

    /**
     * Converts row data to the ONE cache context the row is stored under.
     *
     * @param array<string, mixed> $row
     * @param string|null $generation Pre-query generation snapshot.
     * @return array<string, mixed>|null Null when the row cannot produce a full identity.
     */
    public function toRowContext(array $row, ?string $generation = null): ?array
    {
        $identity = $this->toRowIdentity($row);

        return $identity === null ? null : $this->toIdentityContext($identity, $generation);
    }

    /**
     * Wraps an already-canonical identity (from toRowIdentity()) in the row
     * cache context.
     *
     * @param array<string, mixed> $identity
     * @param string|null $generation Pre-query generation snapshot.
     * @return array<string, mixed>
     */
    public function toIdentityContext(array $identity, ?string $generation = null): array
    {
        return $this->withGeneration(['type' => $this->model, 'identities' => $this->stringifyScalars($identity)], $generation);
    }

    /**
     * Converts a business-key lookup to its alias context: a pointer slot
     * from the lookup to the row's canonical identity. Aliases store
     * identities, never row data, so a stale alias self-heals — the row read
     * it points to misses and falls through to the database.
     *
     * @param array<string, mixed> $ids The caller's lookup key.
     * @param string|null $generation Pre-query generation snapshot.
     * @return array<string, mixed>
     */
    public function toAliasContext(array $ids, ?string $generation = null): array
    {
        $normalized = $this->stringifyScalars($ids);

        ksort($normalized);

        return $this->withGeneration(['type' => $this->model, 'alias' => $normalized], $generation);
    }

    /**
     * The set-level context — one undiscriminated slot per table
     * (estimatedCount today). A second whole-table value would need a
     * discriminator added to the context.
     *
     * @param string|null $generation Pre-query generation snapshot.
     * @return array<string, mixed>
     */
    public function toTableContext(?string $generation = null): array
    {
        return $this->withGeneration(['type' => $this->model], $generation);
    }

    /**
     * The context the generation token itself lives under. Never carries a
     * generation — it IS the generation.
     *
     * @return array<string, mixed>
     */
    public function toGenerationContext(): array
    {
        return ['type' => $this->model, 'generation' => true];
    }

    /**
     * Folds a generation token into a cache context. The token is replaced
     * on every write, which orphans all previously written contexts for the
     * table at once — the transaction-free invalidation primitive the whole
     * design leans on.
     *
     * @param array<string, mixed> $context
     * @param string|null $generation Pre-query generation snapshot; required
     *                                when generations are on (the handler
     *                                threads it), ignored when off.
     * @return array<string, mixed>
     *
     * @see https://developer.wordpress.org/reference/functions/wp_cache_set_last_changed/ the pattern's origin
     */
    public function withGeneration(array $context, ?string $generation): array
    {
        if (!$this->useGenerations) {
            return $context;
        }

        if ($generation === null) {
            // A generation-keyed table must never build an unkeyed context:
            // no bump could ever orphan it, and it would collide with the
            // generation-disabled shape. Failing loudly beats fail-open.
            throw new InvalidArgumentException(
                'A generation snapshot is required to build cache contexts for a generation-keyed table.'
            );
        }

        $context['gen'] = $generation;

        return $context;
    }

    /**
     * True when the snapshot was minted during a token-read outage and no
     * cache entry keyed under it can ever be served.
     */
    public function isEphemeralGeneration(?string $generation): bool
    {
        return $generation !== null && strpos($generation, self::EPHEMERAL_GENERATION_PREFIX) === 0;
    }

    /**
     * True when a stored value reads as a usable generation token.
     *
     * @param mixed $token
     */
    public function isValidGeneration($token): bool
    {
        return is_string($token) && $token !== '';
    }

    /**
     * Compares a model's converted data against the caller's lookup values —
     * the guard against an alias whose business key was rotated out from
     * under it by an identity-keyed update.
     *
     * Tri-state so the caller owns policy: true = every lookup field
     * verified and matching, false = a verified field mismatched, null =
     * not fully verifiable (the model adapter hides at least one lookup
     * field — and a field that cannot be checked is exactly the field a
     * rotation may have changed). Exceptions thrown by the model adapter
     * are domain errors and propagate.
     *
     * @param DataModel $model
     * @param array<string, mixed> $ids The caller's lookup key.
     */
    public function matchesLookup(DataModel $model, array $ids): ?bool
    {
        $data = $this->modelAdapter->toArray($model);
        $verified = 0;

        foreach ($ids as $field => $value) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $verified++;

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

        return $verified === count($ids) ? true : null;
    }

    /**
     * Normalizes scalar values to strings — the single normalization rule
     * every cache key shape shares.
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
