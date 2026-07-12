<?php

namespace PHPNomad\Database\Traits;

use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
use PHPNomad\Datastore\Events\RecordCreated;
use PHPNomad\Datastore\Events\RecordDeleted;
use PHPNomad\Datastore\Events\RecordUpdated;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\DuplicateEntryException;
use PHPNomad\Datastore\Interfaces\CanIdentify;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\HasSingleIntIdentity;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Helpers\Obj;

trait WithDatastoreHandlerMethods
{
    protected DatabaseServiceProvider $serviceProvider;
    protected Table $table;
    protected TableSchemaService $tableSchemaService;

    /**
     * @var class-string<DataModel>
     */
    protected string $model;
    protected ModelAdapter $modelAdapter;

    /**
     * @inheritDoc
     */
    public function getEstimatedCount(): int
    {
        return $this->serviceProvider->cacheableService
            ->getWithCache(Operation::Read, $this->withTableGeneration(['type' => $this->model]), function () {
                return $this->serviceProvider->queryStrategy->estimatedCount($this->table);
            });
    }

    /** @inheritDoc */
    public function where(array $conditions, ?int $limit = null, ?int $offset = null, ?string $orderBy = null, string $order = 'ASC'): array
    {
        try {
            $this->initiateQuery($limit, $offset, $orderBy, $order)->buildConditions($conditions);
            $ids = $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);

            return $this->getModels($ids);
        }catch(RecordNotFoundException $e){
            return [];
        }
    }

    /** @inheritDoc */
    public function andWhere(array $conditions, ?int $limit = null, ?int $offset = null, ?string $orderBy = null, string $order = 'ASC'): array
    {
        return $this->where([
            [
                'type' => 'AND',
                'clauses' => $conditions
            ],
        ], $limit, $offset, $orderBy, $order);
    }

    /** @inheritDoc */
    public function orWhere(array $conditions, ?int $limit = null, ?int $offset = null, ?string $orderBy = null, string $order = 'ASC'): array
    {
        return $this->where([
            [
                'type' => 'OR',
                'clauses' => $conditions
            ]
        ], $limit, $offset, $orderBy, $order);
    }

    /** @inheritDoc */
    public function countWhere(array $conditions): int
    {
        $this->initiateQuery(
            null,
            null,
            null,
            'ASC',
            [],
        )->buildConditions($conditions);

        $this->serviceProvider->queryBuilder->count('*', 'count');

        try {
            $results = $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);
        } catch (RecordNotFoundException $e) {
            return 0;
        }

        $result = (array)Arr::first($results);

        return Arr::get($result, 'count', 0);
    }

    /** @inheritDoc */
    public function countAndWhere(array $conditions): int
    {
        return $this->countWhere([
            [
                'type' => 'AND',
                'clauses' => $conditions
            ]
        ]);
    }

    /** @inheritDoc */
    public function countOrWhere(array $conditions): int
    {
        return $this->countWhere([
            [
                'type' => 'OR',
                'clauses' => $conditions
            ]
        ]);
    }

    /** @inheritDoc */
    public function findBy(string $field, $value): DataModel
    {
        $result = $this->andWhere([['column' => $field, 'operator' => '=', 'value' => $value]], 1);

        if(empty($result)){
            throw new RecordNotFoundException("Could not find a record where $field equals $value");
        }

        return Arr::get($result, 0);
    }

    /** @inheritDoc */
    public function create(array $attributes): DataModel
    {
        $fields = $this->table->getFieldsForIdentity();

        if (Obj::implements($this->model, HasSingleIntIdentity::class)) {
            $attributes = $this->removeIdentifiableFields($attributes, $fields);
        } else {
            $this->maybeThrowForDuplicateIdentity($attributes, $fields);
        }

        $this->maybeThrowForDuplicateUniqueFields($attributes);

        // Apply PHP-side defaults so the values that land in the DB also land
        // in the in-memory model we hand back. This eliminates the post-insert
        // read-back, which is the operation that races read-replicas behind a
        // write/read-split router (ProxySQL, MaxScale, Aurora, etc.).
        $attributes = $this->applyPhpDefaults($attributes);

        $ids = $this->serviceProvider->queryStrategy->insert($this->table, $attributes);

        $row = Arr::merge($attributes, $ids);
        $result = $this->modelAdapter->toModel($row);

        // Bump BEFORE pre-warming so the row entry lands under the new
        // generation and stays readable; set-level caches (estimatedCount)
        // keyed under the old generation become unreachable.
        $generation = $this->bumpTableGeneration();

        // Pre-warm the cache so subsequent reads of this record don't have to
        // round-trip the DB at all. Same canonical key every read path uses,
        // so existing read paths transparently pick it up.
        $this->cacheRow($row, $result, $generation);

        $this->serviceProvider->eventStrategy->broadcast(new RecordCreated($result));

        return $result;
    }

    /**
     * Fills in PHP-side defaults for any column the table declares with a
     * `phpDefault` callable that wasn't already supplied by the caller.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    protected function applyPhpDefaults(array $attributes): array
    {
        foreach ($this->table->getColumns() as $column) {
            $name = $column->getName();
            if (array_key_exists($name, $attributes)) {
                continue;
            }
            $default = $column->getPhpDefault();
            if ($default === null) {
                continue;
            }
            $attributes[$name] = $default();
        }

        return $attributes;
    }

    /**
     * Delete all items that fit the specified condition.
     *
     * @param array $conditions
     * @return void
     * @throws DatastoreErrorException
     */
    public function deleteWhere(array $conditions): void
    {
        try {
            $identityRows = $this->findIds([['type' => 'AND', 'clauses' => $conditions]]);
        } catch (RecordNotFoundException $e) {
            return;
        }

        $generation = $this->snapshotTableGeneration();
        $deleted = false;

        foreach ($identityRows as $identityRow) {
            // Delete by the full table identity. The previous implementation
            // deleted by the MODEL's identity, which can be a subset of the
            // table's — on such tables that SQL delete could reach rows
            // outside the matched set.
            $this->serviceProvider->queryStrategy->delete($this->table, $identityRow);

            $identity = $this->getRowIdentity($identityRow);

            if ($identity !== null) {
                $this->serviceProvider->cacheableService->delete($this->getCanonicalIdentityContext($identity, $generation));
            }

            // The broadcast carries the raw identity row (DB-typed values):
            // the deletion HAPPENED, so listeners must hear about it even
            // when a canonical cache identity could not be derived, and cache
            // key normalization must not leak into the event contract.
            $this->serviceProvider->eventStrategy->broadcast(new RecordDeleted($this->model, $identityRow));

            $deleted = true;
        }

        if ($deleted) {
            $this->bumpTableGeneration();
        }
    }

    /**
     * @return $this
     */
    protected function initiateQuery(?int $limit = null, ?int $offset = null, ?string $orderBy = null, string $order = 'ASC', array $select = null)
    {
        $this->serviceProvider->clauseBuilder->useTable($this->table);
        $select = $select === null ? $this->table->getFieldsForIdentity() : $select;


        $this->serviceProvider->queryBuilder
            ->reset()
            ->from($this->table);

        if(!empty($select)){
            $this->serviceProvider->queryBuilder->select(...$select);
        }

        if ($limit) {
            $this->serviceProvider->queryBuilder->limit($limit);
        }

        if ($offset) {
            $this->serviceProvider->queryBuilder->offset($offset);
        }

        if ($orderBy) {
            $this->serviceProvider->queryBuilder->orderBy($orderBy, $order);
        }

        return $this;
    }

    /**
     * Takes the given array of conditions and adds it to the query builder as a where statement.
     *
     * @param array $groups
     * @return $this
     */
    protected function buildConditions(array $groups)
    {
        foreach ($groups as $group) {
            $clauses = Arr::get($group, 'clauses', []);
            $groupClauseBuilder = (clone $this->serviceProvider->clauseBuilder)->reset()->useTable($this->table);
            $type = strtoupper(Arr::get($group, 'type'));
            if($clauses) {
                $firstClause = array_shift($clauses);
                $column = Arr::get($firstClause, 'column');
                $operator = Arr::get($firstClause, 'operator');
                $value = array_values(Arr::wrap(Arr::get($firstClause, 'value', [])));

                $groupClauseBuilder->where($column, $operator, ...$value);

                foreach ($clauses as $clause) {
                    $column = Arr::get($clause, 'column');
                    $operator = Arr::get($clause, 'operator');
                    $value = Arr::get($clause, 'value');

                    if ($type === 'OR') {
                        $groupClauseBuilder->orWhere($column, $operator, ...Arr::wrap($value));
                    } else {
                        $groupClauseBuilder->andWhere($column, $operator, ...Arr::wrap($value));
                    }
                }

                $type = strtoupper(Arr::get($group, 'type', 'AND'));
                $type = in_array($type, ['AND', 'OR']) ? $type : 'AND';

                $groupType = strtoupper(Arr::get($group, 'groupType', 'and'));

                if ($groupType === 'OR') {
                    $this->serviceProvider->clauseBuilder->orGroup($type, $groupClauseBuilder);
                } else {
                    $this->serviceProvider->clauseBuilder->andGroup($type, $groupClauseBuilder);
                }
            }
        }

        if(!empty($groups)) {
            $this->serviceProvider->queryBuilder->where($this->serviceProvider->clauseBuilder);
        }

        return $this;
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
    protected function getRowIdentity(array $row): ?array
    {
        $identityFields = $this->table->getFieldsForIdentity();

        if (empty($identityFields)) {
            return null;
        }

        $identity = [];

        foreach ($identityFields as $field) {
            if (!array_key_exists($field, $row)) {
                $this->serviceProvider->loggerStrategy->warning(
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

    /**
     * Builds the ONE cache context a row is stored under, derived from row
     * data rather than from whatever shape the caller asked for. Every read
     * and every invalidation goes through this context, so writers can always
     * name the key readers used.
     *
     * @param array<string, mixed> $row
     * @param string|null $generation Generation snapshot to key under; taken fresh when omitted.
     * @return array|null Null when the row cannot produce a full identity.
     */
    protected function getCanonicalRowContext(array $row, ?string $generation = null): ?array
    {
        $identity = $this->getRowIdentity($row);

        return $identity === null ? null : $this->getCanonicalIdentityContext($identity, $generation);
    }

    /**
     * Wraps an already-canonical identity (from getRowIdentity()) in the row
     * cache context.
     *
     * @param array<string, mixed> $identity
     * @param string|null $generation Generation snapshot to key under; taken fresh when omitted.
     */
    protected function getCanonicalIdentityContext(array $identity, ?string $generation = null): array
    {
        return $this->withTableGeneration(['type' => $this->model, 'identities' => $identity], $generation);
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
    protected function getAliasContext(array $ids, ?string $generation = null): array
    {
        $normalized = $this->stringifyScalars($ids);

        ksort($normalized);

        return $this->withTableGeneration(['type' => $this->model, 'alias' => $normalized], $generation);
    }

    /**
     * True when the given compound key is exactly the table's identity field
     * set (order-insensitive).
     *
     * @param array<string, mixed> $ids
     */
    protected function isTableIdentity(array $ids): bool
    {
        $identityFields = $this->table->getFieldsForIdentity();

        return !empty($identityFields)
            && count($ids) === count($identityFields)
            && !array_diff(array_keys($ids), $identityFields);
    }

    /**
     * Caches a single row's model under its canonical row context. Skips
     * silently (already logged by getRowIdentity()) when the row cannot
     * produce a full identity.
     *
     * Callers on read paths MUST pass the generation snapshot they took
     * before querying the database: rebuilding the context here with a fresh
     * token would let a stale row land under a generation minted AFTER a
     * concurrent write — reopening the exact race generations exist to close.
     *
     * @param array<string, mixed> $row
     * @param DataModel $model
     * @param string|null $generation Pre-query generation snapshot.
     */
    protected function cacheRow(array $row, DataModel $model, ?string $generation = null): void
    {
        $context = $this->getCanonicalRowContext($row, $generation);

        if ($context !== null) {
            $this->serviceProvider->cacheableService->set($context, $model);
        }
    }

    /**
     * Whether this table's cache contexts are keyed under a per-table
     * generation token. On by default; override to opt a write-hot table out
     * (accepting TTL-bounded staleness on the read-back race in exchange for
     * a higher hit rate).
     */
    protected function shouldUseTableGenerations(): bool
    {
        return true;
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
    protected function withTableGeneration(array $context, ?string $generation = null): array
    {
        if (!$this->shouldUseTableGenerations()) {
            return $context;
        }

        $context['gen'] = $generation ?? $this->getTableGeneration();

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
    protected function snapshotTableGeneration(): ?string
    {
        return $this->shouldUseTableGenerations() ? $this->getTableGeneration() : null;
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
    protected function getTableGeneration(): string
    {
        $context = ['type' => $this->model, 'generation' => true];

        $token = $this->getCachedValue($context);

        if (!is_string($token) || $token === '') {
            $token = $this->mintTableGeneration();
            $this->serviceProvider->cacheableService->set($context, $token);
        }

        return $token;
    }

    /**
     * Replaces the table's generation token. Called after every successful
     * write. A no-op when generations are disabled for this table.
     *
     * @return string|null The freshly minted token, so post-write cache
     *                     writes (create()'s pre-warm) can key under it; null
     *                     when generations are disabled.
     */
    protected function bumpTableGeneration(): ?string
    {
        if (!$this->shouldUseTableGenerations()) {
            return null;
        }

        $token = $this->mintTableGeneration();

        $this->serviceProvider->cacheableService->set(
            ['type' => $this->model, 'generation' => true],
            $token
        );

        return $token;
    }

    /**
     * Mints an opaque, unique generation token.
     */
    protected function mintTableGeneration(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Cache read that treats "not found" as null instead of an exception.
     *
     * @return mixed|null
     */
    protected function getCachedValue(array $context)
    {
        try {
            $value = $this->serviceProvider->cacheableService->get($context);
        } catch (CachedItemNotFoundException $e) {
            return null;
        }

        return $value;
    }

    /**
     * @param array $conditions
     * @param int|null $limit
     * @param int|null $offset
     * @return array
     * @throws DatastoreErrorException
     */
    public function findIds(array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        $this->serviceProvider->queryBuilder
            ->from($this->table)
            ->select(...$this->table->getFieldsForIdentity());


        if ($limit) {
            $this->serviceProvider->queryBuilder->limit($limit);
        }

        if ($offset) {
            $this->serviceProvider->queryBuilder->offset($offset);
        }

        $this->buildConditions($conditions);

        return $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);
    }

    /**
     * Gets the models from the specified list of IDs.
     *
     * @param array<string, int>[] $ids
     * @return array
     */
    /**
     * Gets the models for the given identity rows, read-through cached.
     *
     * @param array<string, int|string>[] $ids Identity rows from findIds() (values arrive DB-typed).
     * @return DataModel[]
     */
    protected function getModels(array $ids): array
    {
        // One generation snapshot for the whole operation, taken BEFORE any
        // database read — see cacheRow() for why this must not be re-fetched
        // at write-back time.
        $generation = $this->snapshotTableGeneration();

        // Filter out the items that are currently in the cache.
        $idsToQuery = Arr::filter(
            $ids,
            function (array $identityRow) use ($generation) {
                $context = $this->getCanonicalRowContext($identityRow, $generation);

                return $context === null || !$this->serviceProvider->cacheableService->exists($context);
            }
        );

        if (!empty($idsToQuery)) {
            $clauseBuilder = (clone $this->serviceProvider->clauseBuilder)->reset()->useTable($this->table);
            // Get the things that aren't in the cache.
            $data = $this->serviceProvider->queryStrategy->query(
                $this->serviceProvider->queryBuilder
                    ->from($this->table)
                    ->select('*')
                    ->where($clauseBuilder->andWhere($this->table->getFieldsForIdentity(), 'IN', ...$idsToQuery))
            );

            // Cache those items under their canonical row contexts, keyed
            // from the ROW's identity values — never the model's getIdentity(),
            // which is not necessarily the table's identity.
            foreach ($data as $row) {
                $this->cacheRow($row, $this->modelAdapter->toModel($row), $generation);
            }
        }

        // Now, use the cache to get all the posts in the proper order.
        return Arr::map($ids, fn(array $id) => $this->findFromCompound($id, $generation));
    }

    /**
     * Finds a single record by compound key. A key that IS the table
     * identity addresses the canonical row entry directly; any other key (a
     * business key) resolves through an alias entry first.
     *
     * @param non-empty-array<string, mixed> $ids
     * @param string|null $generation Pre-query generation snapshot; taken here when the caller has none.
     * @return DataModel
     * @throws DatastoreErrorException
     * @throws RecordNotFoundException
     */
    protected function findFromCompound(array $ids, ?string $generation = null)
    {
        if (empty($ids)) {
            throw new RecordNotFoundException('Record cannot be found, no IDs provided.');
        }

        $generation = $generation ?? $this->snapshotTableGeneration();

        // Canonical lookup: the caller's key IS the table identity, so the
        // row entry can be addressed directly (after normalizing order/types).
        if ($this->isTableIdentity($ids)) {
            $identity = $this->getRowIdentity($ids);

            return $this->serviceProvider->cacheableService->getWithCache(
                Operation::Read,
                $this->getCanonicalIdentityContext($identity, $generation),
                fn () => $this->queryRowAndModel($ids)[1]
            );
        }

        // Business-key lookup: resolve through an alias entry so the row is
        // still cached exactly once, under its canonical identity.
        $aliasContext = $this->getAliasContext($ids, $generation);
        $aliasedIdentity = $this->getCachedValue($aliasContext);

        if (is_array($aliasedIdentity) && $this->isTableIdentity($aliasedIdentity)) {
            try {
                return $this->findFromCompound($aliasedIdentity, $generation);
            } catch (RecordNotFoundException $e) {
                // Stale alias — the row it points at moved or died. Drop it
                // and re-resolve from the database.
                $this->serviceProvider->cacheableService->delete($aliasContext);
            }
        }

        [$row, $model] = $this->queryRowAndModel($ids);

        $identity = $this->getRowIdentity($row);

        if ($identity !== null) {
            $this->serviceProvider->cacheableService->set($aliasContext, $identity);
            $this->cacheRow($row, $model, $generation);
        }

        return $model;
    }

    /**
     * Queries a single row by the given compound key and hydrates it.
     *
     * @param array<string, mixed> $ids
     * @return array{0: array<string, mixed>, 1: DataModel} The raw row and its model.
     * @throws RecordNotFoundException When no row matches.
     * @throws DatastoreErrorException
     */
    protected function queryRowAndModel(array $ids): array
    {
        $clauseBuilder = (clone $this->serviceProvider->clauseBuilder)->reset()->useTable($this->table);

        foreach ($ids as $key => $id) {
            $clauseBuilder->andWhere($key, '=', $id);
        }

        $items = $this->serviceProvider->queryStrategy->query(
            $this->serviceProvider->queryBuilder
                ->select('*')
                ->from($this->table)
                ->where($clauseBuilder)
                ->limit(1)
        );

        $item = Arr::get($items, 0);

        if (!$item) {
            throw new RecordNotFoundException(sprintf(
                'Record not found in table "%s" using identity %s.',
                $this->table->getName(),
                $this->encodeExceptionContext($ids)
            ));
        }

        return [$item, $this->modelAdapter->toModel($item)];
    }

    /** @inheritDoc */
    public function updateCompound($ids, array $attributes): void
    {
        $generation = $this->snapshotTableGeneration();

        // The pre-read warms the alias (business-key callers) or the row
        // entry (canonical callers) — which is what makes the canonical
        // identity resolvable below without an extra query.
        $record = $this->findFromCompound($ids, $generation);
        $this->maybeThrowForDuplicateUniqueFields($attributes, $ids);

        $identity = $this->resolveTableIdentity($ids, $generation);

        $this->serviceProvider->queryStrategy->update($this->table, $ids, $attributes);

        // Precise invalidation first, under the pre-write generation (the
        // one readers wrote their entries with), then bump. The precise
        // deletes carry tables that opt out of generations; the bump closes
        // the cache-aside race for everyone else.
        if ($identity !== null) {
            $this->serviceProvider->cacheableService->delete($this->getCanonicalIdentityContext($identity, $generation));
        }

        if (!$this->isTableIdentity($ids)) {
            // Drop the alias too: the update may have moved the row's
            // business key or identity out from under it.
            $this->serviceProvider->cacheableService->delete($this->getAliasContext($ids, $generation));
        }

        $this->bumpTableGeneration();

        $this->serviceProvider->eventStrategy->broadcast(new RecordUpdated($record::class, $ids, $attributes));
    }

    /**
     * Resolves the caller's compound key to the row's canonical table
     * identity: directly when the key IS the table identity, via the alias
     * entry (warmed by the pre-read) otherwise.
     *
     * For generation-disabled tables the alias is the ONLY invalidation
     * route — there is no bump to fall back on — so an alias miss (evicted
     * between the pre-read and here) resolves from the database instead of
     * silently skipping precise invalidation.
     *
     * @param array<string, mixed> $ids
     * @param string|null $generation Generation snapshot for the alias lookup.
     * @return array<string, mixed>|null
     */
    protected function resolveTableIdentity(array $ids, ?string $generation = null): ?array
    {
        if ($this->isTableIdentity($ids)) {
            return $this->getRowIdentity($ids);
        }

        $aliased = $this->getCachedValue($this->getAliasContext($ids, $generation));

        if (is_array($aliased) && $this->isTableIdentity($aliased)) {
            return $aliased;
        }

        if ($this->shouldUseTableGenerations()) {
            return null;
        }

        try {
            [$row] = $this->queryRowAndModel($ids);

            return $this->getRowIdentity($row);
        } catch (RecordNotFoundException $e) {
            return null;
        }
    }

    /**
     * @param array $attributes
     * @param array $fields
     * @return void
     * @throws DatastoreErrorException
     * @throws DuplicateEntryException
     */
    protected function maybeThrowForDuplicateIdentity(array $attributes, array $fields): void
    {
        $ids = [];

        foreach ($attributes as $fieldName => $value) {
            if (in_array($fieldName, $fields)) {
                $ids[$fieldName] = $value;
            }
        }

        // Validate item does not already exist.
        if (count($ids) === count($fields)) {
            try {
                $this->findFromCompound($ids);

                $identity = Arr::process($ids)
                    ->each(fn($item, $key) => $key . ' => ' . $item)
                    ->setSeparator(', ')
                    ->toString();

                throw new DuplicateEntryException('The specified item identified as ' . $identity . ' already exists.');
            } catch (RecordNotFoundException $e) {
                //continue
            }
        }
    }

    /**
     * @param array $attributes
     * @param array $fields
     * @return array
     */
    protected function removeIdentifiableFields(array $attributes, array $fields): array
    {
        return Arr::filter($attributes, fn($value, $fieldName) => !in_array($fieldName, $fields));
    }

    /**
     * Looks up records to check if a record with the specified unique columns already exists.
     *
     * @param array $data
     * @return DataModel[] List of existing items that match the unique constraints.
     * @throws DatastoreErrorException
     * @throws RecordNotFoundException
     */
    protected function getDuplicates(array $data): array
    {
        $uniqueColumnGroups = $this->tableSchemaService->getUniqueColumns($this->table);
        $groups = [];

        foreach ($uniqueColumnGroups as $uniqueColumns) {
            $clauses = [];
            foreach ($uniqueColumns as $columnName) {
                if (isset($data[$columnName])) {
                    $clauses[] = [
                        'column' => $columnName,
                        'operator' => '=',
                        'value' => $data[$columnName]
                    ];
                } else {
                    // If any column in a unique index (compound key) does not have data provided, skip this group
                    $clauses = [];
                    break;
                }
            }

            // Only add this group to the query if it has conditions for all columns in the unique index
            if (!empty($clauses)) {
                $groups[] = [
                    'type' => 'AND',
                    'groupType' => 'OR',
                    'clauses' => $clauses
                ];
            }
        }

        if(empty($groups)){
            return [];
        }

        return $this->where($groups); // Assuming where method is adapted to handle groups
    }


    /**
     * @param array $data
     * @param array|null $updateIdentity
     * @return void
     * @throws DuplicateEntryException
     * @throws DatastoreErrorException
     */
    protected function maybeThrowForDuplicateUniqueFields(array $data, ?array $updateIdentity = null): void
    {
        try {
            $duplicates = $this->getDuplicates($data);

            // If an identity is provided, filter out items that have the provided identity.
            if (!is_null($updateIdentity)) {
                $duplicates = Arr::filter(
                    $duplicates,
                    fn(CanIdentify $existingItem) => !Arr::containsSameData($existingItem->getIdentity(), $updateIdentity)
                );
            }
        } catch (RecordNotFoundException $e) {
            // Bail if no records were found.
            return;
        }

        if (!empty($duplicates)) {
            throw new DuplicateEntryException('Database operation stopped early because duplicate entries were detected.');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    protected function encodeExceptionContext(array $context): string
    {
        $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[unserializable context]' : $encoded;
    }
}
