<?php

namespace PHPNomad\Database\Traits;

use PHPNomad\Datastore\Events\RecordCreated;
use PHPNomad\Datastore\Events\RecordDeleted;
use PHPNomad\Datastore\Events\RecordUpdated;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Interfaces\RowCache;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\DuplicateEntryException;
use PHPNomad\Datastore\Interfaces\CanIdentify;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\HasSingleIntIdentity;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
use PHPNomad\Utils\Helpers\Arr;
use PHPNomad\Utils\Helpers\Obj;
use Throwable;

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
    protected ?RowCache $rowCacheService = null;

    /**
     * @inheritDoc
     */
    public function getEstimatedCount(): int
    {
        return $this->rowCache()->readTableValue(function () {
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

        // The insert is committed: cache maintenance below is best-effort
        // and must not fail the create or suppress RecordCreated. Bump
        // BEFORE pre-warming so the row entry lands under the new
        // generation and stays readable; set-level caches keyed under the
        // old generation become unreachable. Bump and pre-warm failures are
        // logged separately — the first means stale set-level caches until
        // TTL, the second only a missed warm-up.
        $generation = $this->invalidateAfterWriteSafely();

        try {
            // Pre-warm the cache so subsequent reads of this record don't
            // have to round-trip the DB at all. Same canonical key every
            // read path uses, so existing read paths transparently pick it up.
            $this->rowCache()->storeRow($row, $result, $generation);
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->warning(
                'Post-create pre-warm failed — the row was created but the first read will hit the database.',
                ['table' => $this->table->getName(), 'exception' => $e->getMessage()]
            );
        }

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
     * Deletes every row matching the conditions, by full table identity.
     * Each deleted row broadcasts a RecordDeleted carrying its raw identity
     * row; no matching rows is a silent no-op.
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

        $generation = $this->rowCache()->snapshotGeneration();
        $deleted = false;
        $broadcastQueue = [];

        // Alias entries pointing at deleted rows are left to self-heal: the
        // row read they resolve to misses and falls through to the database.
        // The finally block guarantees the generation bump lands even when a
        // later row's SQL delete throws — rows already deleted (and
        // broadcast) must not leave set-level caches serving stale data.
        try {
            foreach ($identityRows as $identityRow) {
                // Delete by the full table identity. The previous implementation
                // deleted by the MODEL's identity, which can be a subset of the
                // table's — on such tables that SQL delete could reach rows
                // outside the matched set.
                $this->serviceProvider->queryStrategy->delete($this->table, $identityRow);

                try {
                    $identity = $this->rowCache()->rowIdentity($identityRow);

                    if ($identity !== null) {
                        $this->rowCache()->deleteRow($identity, $generation);
                    }
                } catch (Throwable $e) {
                    // Cache trouble must not abort the remaining SQL deletes;
                    // the finally-block invalidation is the safety net.
                    $this->serviceProvider->loggerStrategy->warning(
                        'Cache invalidation failed for a deleted row.',
                        ['table' => $this->table->getName(), 'exception' => $e->getMessage()]
                    );
                }

                $broadcastQueue[] = $identityRow;
                $deleted = true;
            }
        } finally {
            if ($deleted) {
                $this->invalidateAfterWriteSafely();
            }

            // Broadcasts are buffered and emitted after the SQL work so a
            // throwing listener cannot abort a bulk delete mid-set, and
            // emitted per-row inside the finally so rows deleted before a
            // mid-loop SQL failure still announce themselves. Each carries
            // the raw identity row (DB-typed values): the deletion HAPPENED,
            // listeners must hear about it even when no cache identity could
            // be derived, and cache-key normalization must not leak into the
            // event contract.
            foreach ($broadcastQueue as $deletedIdentityRow) {
                try {
                    $this->serviceProvider->eventStrategy->broadcast(new RecordDeleted($this->model, $deletedIdentityRow));
                } catch (Throwable $e) {
                    $this->serviceProvider->loggerStrategy->error(
                        'A RecordDeleted listener failed; remaining deletion events still fire.',
                        ['table' => $this->table->getName(), 'exception' => $e->getMessage()]
                    );
                }
            }
        }
    }

    /**
     * Post-write invalidation that never throws: a cache-layer failure after
     * a committed database write must not turn the write into a
     * caller-visible error, mask an in-flight exception, or suppress event
     * broadcasts. Failures are logged and left to TTL recovery.
     *
     * @return string|null The fresh generation token, or null when
     *                     generations are disabled or the cache failed.
     */
    protected function invalidateAfterWriteSafely(): ?string
    {
        try {
            return $this->rowCache()->invalidateAfterWrite();
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->error(
                'Post-write cache invalidation failed — cached rows may serve stale data until TTL.',
                ['table' => $this->table->getName(), 'exception' => $e->getMessage()]
            );

            return null;
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
     * Lazily builds the per-table row-cache collaborator that owns every
     * cache context this trait uses (canonical row identities, alias
     * entries, generation tokens).
     *
     * @see \PHPNomad\Database\Interfaces\RowCache
     */
    protected function rowCache(): RowCache
    {
        return $this->rowCacheService ??= $this->serviceProvider->rowCacheFactory->make(
            $this->table,
            $this->model,
            $this->modelAdapter,
            $this->shouldUseTableGenerations()
        );
    }

    /**
     * Whether this table's cache contexts are keyed under a per-table
     * generation token. On by default; override to opt a write-hot table out
     * in exchange for a higher hit rate.
     *
     * What opting out costs: the cache-aside read-back race is only
     * TTL-bounded (a slow reader can write a just-invalidated row back), and
     * set-level caches plus rotated aliases fall to precise handling
     * (RowCache::invalidateAfterWrite()'s set-level delete and the read-time
     * RowCache::matchesLookup() verification in findFromCompound()) instead
     * of being orphaned wholesale by the bump.
     */
    protected function shouldUseTableGenerations(): bool
    {
        return true;
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
            ->reset()
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
     * Gets the models for the given identity rows, read-through cached.
     *
     * @param array<string, int|string>[] $ids Identity rows from findIds() (values arrive DB-typed).
     * @return DataModel[]
     */
    protected function getModels(array $ids): array
    {
        // One generation snapshot for the whole operation, taken BEFORE any
        // database read — see RowCache::storeRow() for why this must not be
        // re-fetched at write-back time.
        $generation = $this->rowCache()->snapshotGeneration();

        // Filter out the items that are currently in the cache.
        $idsToQuery = Arr::filter(
            $ids,
            fn (array $identityRow) => !$this->rowCache()->hasRow($identityRow, $generation)
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
                $this->rowCache()->storeRow($row, $this->modelAdapter->toModel($row), $generation);
            }
        }

        // Now, use the cache to return the rows in the requested order.
        return Arr::map($ids, fn(array $id) => $this->findFromCompound($id, $generation));
    }

    /**
     * Finds a single record by compound key. A key that IS the table
     * identity addresses the canonical row entry directly; any other key (a
     * business key) resolves through an alias entry first.
     *
     * @param array<string, mixed> $ids Must be non-empty; an empty key throws RecordNotFoundException.
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

        $generation = $generation ?? $this->rowCache()->snapshotGeneration();

        // Canonical lookup: the caller's key IS the table identity, so the
        // row entry can be addressed directly (after normalizing order/types).
        if ($this->rowCache()->isTableIdentity($ids)) {
            $identity = $this->rowCache()->rowIdentity($ids);

            return $this->rowCache()->readRow($identity, $generation, fn () => $this->queryRowAndModel($ids)[1]);
        }

        // Business-key lookup: resolve through an alias entry so the row is
        // still cached exactly once, under its canonical identity.
        $aliasedIdentity = $this->rowCache()->resolveAliasedIdentity($ids, $generation);

        if ($aliasedIdentity !== null) {
            try {
                $model = $this->findFromCompound($aliasedIdentity, $generation);

                // Verify the resolved row still matches the caller's lookup
                // values. An identity-keyed update can rotate a business key
                // out from under its alias, and on generation-disabled
                // tables nothing else would ever notice — the alias would
                // keep serving fresh-looking rows for a key they no longer
                // carry.
                if ($this->rowCache()->matchesLookup($model, $ids)) {
                    return $model;
                }

                $this->rowCache()->deleteAlias($ids, $generation);
            } catch (RecordNotFoundException $e) {
                // Stale alias — the row it points at moved or died. Drop it
                // and re-resolve from the database.
                $this->rowCache()->deleteAlias($ids, $generation);
            }
        }

        [$row, $model] = $this->queryRowAndModel($ids);

        $identity = $this->rowCache()->rowIdentity($row);

        if ($identity !== null) {
            $this->rowCache()->storeAlias($ids, $identity, $generation);
            $this->rowCache()->storeRow($row, $model, $generation);
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
                'Record not found in table "%s" using lookup key %s.',
                $this->table->getName(),
                $this->encodeExceptionContext($ids)
            ));
        }

        return [$item, $this->modelAdapter->toModel($item)];
    }

    /** @inheritDoc */
    public function updateCompound($ids, array $attributes): void
    {
        $generation = $this->rowCache()->snapshotGeneration();

        // The pre-read warms the alias (business-key callers) or the row
        // entry (canonical callers) — which is what makes the canonical
        // identity resolvable below without an extra query. It also throws
        // RecordNotFoundException before any write when the record is gone.
        $this->findFromCompound($ids, $generation);
        $this->maybeThrowForDuplicateUniqueFields($attributes, $ids);

        $identity = $this->resolveTableIdentity($ids, $generation);

        // The SQL update targets the RESOLVED table identity when the
        // pre-read could produce one: updateCompound's contract is "update
        // THE record this key identifies" (the pre-read is limit(1) and one
        // RecordUpdated fires), so a non-unique business key must not fan
        // the write out to rows the invalidation below never saw. The
        // caller's key is the fallback only when resolution failed.
        $this->serviceProvider->queryStrategy->update($this->table, $identity ?? $ids, $attributes);

        // The DB write is committed: everything below is best-effort cache
        // maintenance, and a cache-layer failure must not turn the
        // successful update into a caller-visible error or suppress the
        // RecordUpdated broadcast. Precise deletes run under the pre-write
        // generation (the one readers wrote their entries with), then the
        // finally-block bump closes the cache-aside race — precise deletes
        // carry tables that opt out of generations.
        try {
            if ($identity !== null) {
                $this->rowCache()->deleteRow($identity, $generation);
            }

            if (!$this->rowCache()->isTableIdentity($ids)) {
                // Drop the alias too: the update may have moved the row's
                // business key or identity out from under it.
                $this->rowCache()->deleteAlias($ids, $generation);
            }
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->error(
                'Post-update cache invalidation failed — the generation bump is the remaining safety net.',
                ['table' => $this->table->getName(), 'exception' => $e->getMessage()]
            );
        } finally {
            $this->invalidateAfterWriteSafely();
        }

        // The event intentionally carries the caller's key — the lookup
        // contract they wrote against — not the cache-normalized identity
        // the SQL targeted.
        $this->serviceProvider->eventStrategy->broadcast(new RecordUpdated($this->model, $ids, $attributes));
    }

    /**
     * Resolves the caller's compound key to the row's canonical table
     * identity: directly when the key IS the table identity, via the alias
     * entry (warmed by the pre-read) otherwise.
     *
     * An alias miss (evicted between the pre-read and here, or a cache
     * outage) resolves from the database: the caller targets its SQL write
     * at this identity, so resolution must not silently degrade.
     *
     * @param array<string, mixed> $ids
     * @param string|null $generation Generation snapshot for the alias lookup.
     * @return array<string, mixed>|null
     */
    protected function resolveTableIdentity(array $ids, ?string $generation = null): ?array
    {
        if ($this->rowCache()->isTableIdentity($ids)) {
            return $this->rowCache()->rowIdentity($ids);
        }

        $aliased = $this->rowCache()->resolveAliasedIdentity($ids, $generation);

        if ($aliased !== null) {
            return $aliased;
        }

        // The alias should have been warmed by the pre-read; reaching here
        // means it was evicted or the cache is down. Resolve from the
        // database regardless of generation mode — the SQL update targets
        // this identity, so skipping resolution would reopen the
        // multi-row fan-out for non-unique business keys.
        try {
            [$row] = $this->queryRowAndModel($ids);

            return $this->rowCache()->rowIdentity($row);
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
