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

        // The insert is committed: the post-write invalidation below (a
        // generation bump, or a set-level delete for opted-out tables) is
        // best-effort and must not fail the create or suppress RecordCreated. There is
        // deliberately NO cache pre-warm: a model hydrated from write
        // attributes carries request-typed scalars instead of column types
        // (#29), and a pre-warm keyed under create's own post-insert bump
        // can seed the newest generation with a row another writer already
        // overwrote. The first read after create costs one DB round-trip
        // and is always correct.
        $this->rowCache()->invalidateAfterWrite();

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
        // The finally block guarantees the post-write invalidation (bump, or
        // set-level delete for opted-out tables) lands even when a
        // later row's SQL delete throws — rows already deleted (and
        // broadcast) must not leave set-level caches serving stale data.
        try {
            foreach ($identityRows as $identityRow) {
                // Delete by the full table identity. The previous implementation
                // deleted by the MODEL's identity, which can be a subset of the
                // table's — on such tables that SQL delete could reach rows
                // outside the matched set.
                $this->serviceProvider->queryStrategy->delete($this->table, $identityRow);

                $identity = $this->rowCache()->rowIdentity($identityRow);

                if ($identity !== null) {
                    // Swallow-and-log inside RowCache: cache trouble cannot
                    // abort the remaining SQL deletes.
                    $this->rowCache()->deleteRow($identity, $generation);
                }

                $broadcastQueue[] = $identityRow;
                $deleted = true;
            }
        } finally {
            if ($deleted) {
                $this->rowCache()->invalidateAfterWrite();
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
                        ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exception' => $e->getMessage()]
                    );
                }
            }
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
     * entries, generation tokens). Lazy `??=` instead of constructor
     * injection because a trait cannot extend its consumer's constructor,
     * and the factory needs $table/$model/$modelAdapter, which consumers
     * set after construction.
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
        $this->serviceProvider->clauseBuilder->reset()->useTable($this->table);

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

        $hydrated = [];

        if (!empty($idsToQuery)) {
            $clauseBuilder = (clone $this->serviceProvider->clauseBuilder)->reset()->useTable($this->table);
            // Get the things that aren't in the cache.
            $data = $this->serviceProvider->queryStrategy->query(
                $this->serviceProvider->queryBuilder
                    ->reset()
                    ->from($this->table)
                    ->select('*')
                    ->where($clauseBuilder->andWhere($this->table->getFieldsForIdentity(), 'IN', ...$idsToQuery))
            );

            // Cache those items under their canonical row contexts, keyed
            // from the ROW's identity values — never the model's getIdentity(),
            // which is not necessarily the table's identity. The hydrated
            // models are ALSO held locally: the batch result must not depend
            // on the cache write landing, or a cache outage silently turns
            // one list read into 1+N database queries.
            foreach ($data as $row) {
                $model = $this->modelAdapter->toModel($row);
                $key = $this->rowCache()->identityKey($row);

                if ($key !== null) {
                    $hydrated[$key] = $model;
                    $this->rowCache()->storeRow($row, $model, $generation);
                }
            }
        }

        // Return the rows in the requested order: just-hydrated models are
        // served directly; only ids skipped as already-cached consult the
        // cache (falling through to the database on a miss).
        $models = [];

        foreach ($ids as $id) {
            $key = $this->rowCache()->identityKey($id);

            if ($key !== null && array_key_exists($key, $hydrated)) {
                $models[] = $hydrated[$key];

                continue;
            }

            try {
                $models[] = $this->findFromCompound($id, $generation);
            } catch (RecordNotFoundException $e) {
                // The row vanished between the id query and hydration (a
                // concurrent delete). Skip it — letting this escape would
                // collapse the WHOLE result to [] in where()'s catch,
                // discarding rows that still exist.
                continue;
            }
        }

        return $models;
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
                ->reset()
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
        $record = $this->findFromCompound($ids, $generation);

        $identity = $this->resolveTableIdentity($ids, $generation);

        if ($identity === null) {
            // The pre-read saw the record, but it cannot be re-resolved to a
            // table identity now — it vanished mid-operation. Falling back
            // to the caller's raw key would fan a non-unique business key
            // out to rows the invalidation below never saw, so this fails
            // the same way the pre-read would have.
            throw new RecordNotFoundException(sprintf(
                'Record could not be re-resolved for update in table "%s" using lookup key %s.',
                $this->table->getName(),
                $this->encodeExceptionContext($ids)
            ));
        }

        // Self-matches in the duplicate scan are filtered by TABLE identity
        // (falling back to model identity only when an adapter cannot expose
        // one): model identities can be shared by distinct rows, and a true
        // duplicate must not hide behind one.
        $this->maybeThrowForDuplicateUniqueFields($attributes, $this->rowCache()->rowIdentity($identity), $record->getIdentity());

        // The SQL update targets the RESOLVED table identity AND the
        // caller's own lookup fields: the identity pins exactly one row (no
        // non-unique-business-key fan-out), and keeping the caller's fields
        // in the WHERE re-conditions the write on the key they asked for —
        // a concurrent rotation between resolution and UPDATE makes this a
        // no-op instead of updating a row that no longer carries the key.
        // (QueryStrategy::update() returns void, so a no-op write still
        // broadcasts; documented as a known limitation.)
        $conditions = $this->rowCache()->isTableIdentity($ids) ? $identity : Arr::merge($ids, $identity);
        $this->serviceProvider->queryStrategy->update($this->table, $conditions, $attributes);

        // The DB write is committed: the invalidation below is best-effort
        // (RowCache mutations swallow-and-log cache failures) and runs under
        // the pre-write generation — the one readers wrote their entries
        // with. The bump closes the cache-aside race; precise deletes carry
        // tables that opt out of generations.
        $canonicalIdentity = $this->rowCache()->rowIdentity($identity);

        if ($canonicalIdentity !== null) {
            $this->rowCache()->deleteRow($canonicalIdentity, $generation);
        }

        if (!$this->rowCache()->isTableIdentity($ids)) {
            // Drop the alias too: the update may have moved the row's
            // business key or identity out from under it.
            $this->rowCache()->deleteAlias($ids, $generation);
        }

        $this->rowCache()->invalidateAfterWrite();

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
        $identityFields = array_flip($this->table->getFieldsForIdentity());

        // Raw caller/row values are preferred: this identity feeds the SQL
        // WHERE, and cache-key stringification is cache vocabulary that
        // should not leak into driver-typed comparisons. (The cache delete
        // canonicalizes separately via rowIdentity().)
        if ($this->rowCache()->isTableIdentity($ids)) {
            return array_intersect_key($ids, $identityFields);
        }

        $aliased = $this->rowCache()->resolveAliasedIdentity($ids, $generation);

        if ($aliased !== null) {
            // Alias entries store the canonical (stringified) identity — the
            // only form available without a query. The merged WHERE keeps
            // the caller's raw business key alongside it, and SQL drivers
            // coerce numeric strings.
            return $aliased;
        }

        // The alias should have been warmed by the pre-read; reaching here
        // means it was evicted or the cache is down. Resolve from the
        // database regardless of generation mode — the SQL update targets
        // this identity, so skipping resolution would reopen the
        // multi-row fan-out for non-unique business keys.
        try {
            [$row] = $this->queryRowAndModel($ids);

            $identity = array_intersect_key($row, $identityFields);

            return count($identity) === count($identityFields) ? $identity : null;
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
     * Guards unique-column groups. When updating, the record being updated
     * is filtered out of the duplicate scan by TABLE identity — model
     * identities can be a subset of the table's and therefore shared across
     * distinct rows, so a model-identity self-match could hide a true
     * duplicate. The model-identity comparison is only the fallback for
     * adapters that cannot expose the table identity (that fallback CAN
     * shadow a duplicate sharing the model identity; such adapters trade
     * that for not tripping spurious self-duplicates).
     *
     * @param array $data
     * @param array|null $updateTableIdentity Canonical identity of the record being updated.
     * @param array|null $updateModelIdentity Model identity of the record being updated.
     * @return void
     * @throws DuplicateEntryException
     * @throws DatastoreErrorException
     */
    protected function maybeThrowForDuplicateUniqueFields(array $data, ?array $updateTableIdentity = null, ?array $updateModelIdentity = null): void
    {
        try {
            $duplicates = $this->getDuplicates($data);

            if ($updateTableIdentity !== null || $updateModelIdentity !== null) {
                $duplicates = Arr::filter($duplicates, function (CanIdentify $existingItem) use ($updateTableIdentity, $updateModelIdentity) {
                    $existingTableIdentity = $this->rowCache()->rowIdentity($this->modelAdapter->toArray($existingItem));

                    if ($existingTableIdentity !== null && $updateTableIdentity !== null) {
                        return !Arr::containsSameData($existingTableIdentity, $updateTableIdentity);
                    }

                    return $updateModelIdentity === null
                        || !Arr::containsSameData($existingItem->getIdentity(), $updateModelIdentity);
                });
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
