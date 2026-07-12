<?php

namespace PHPNomad\Database\Traits;

use PHPNomad\Datastore\Events\RecordCreated;
use PHPNomad\Datastore\Events\RecordDeleted;
use PHPNomad\Datastore\Events\RecordUpdated;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
use PHPNomad\Database\Adapters\RowCacheContextAdapter;
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

    /**
     * @var ModelAdapter<DataModel>
     */
    protected ModelAdapter $modelAdapter;
    protected ?RowCacheContextAdapter $rowCacheContextAdapter = null;

    /**
     * @inheritDoc
     */
    public function getEstimatedCount(): int
    {
        $count = $this->readTableValueThrough(function () {
            return $this->serviceProvider->queryStrategy->estimatedCount($this->table);
        });

        if (is_numeric($count)) {
            return (int) $count;
        }

        // Poisoned set-level slot (row and alias slots evict-and-repair;
        // this one is bypassed until the next invalidation) — serve the
        // database's answer rather than a garbage cast.
        return $this->serviceProvider->queryStrategy->estimatedCount($this->table);
    }

    /**
     * @inheritDoc
     *
     * @param array<int, array<string, mixed>> $conditions
     * @return DataModel[]
     */
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

    /**
     * @inheritDoc
     *
     * @param array<int, array<string, mixed>> $conditions
     * @return DataModel[]
     */
    public function andWhere(array $conditions, ?int $limit = null, ?int $offset = null, ?string $orderBy = null, string $order = 'ASC'): array
    {
        return $this->where([
            [
                'type' => 'AND',
                'clauses' => $conditions
            ],
        ], $limit, $offset, $orderBy, $order);
    }

    /**
     * @inheritDoc
     *
     * @param array<int, array<string, mixed>> $conditions
     * @return DataModel[]
     */
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

    /**
     * @inheritDoc
     *
     * @param array<int, array<string, mixed>> $conditions
     */
    public function countAndWhere(array $conditions): int
    {
        return $this->countWhere([
            [
                'type' => 'AND',
                'clauses' => $conditions
            ]
        ]);
    }

    /**
     * @inheritDoc
     *
     * @param array<int, array<string, mixed>> $conditions
     */
    public function countOrWhere(array $conditions): int
    {
        return $this->countWhere([
            [
                'type' => 'OR',
                'clauses' => $conditions
            ]
        ]);
    }

    /**
     * @inheritDoc
     *
     * @param mixed $value
     */
    public function findBy(string $field, $value): DataModel
    {
        $result = $this->andWhere([['column' => $field, 'operator' => '=', 'value' => $value]], 1);
        $model = $result[0] ?? null;

        if (!$model instanceof DataModel) {
            throw new RecordNotFoundException(sprintf('Could not find a record in table "%s" using lookup key %s.', $this->table->getName(), $this->encodeExceptionContext([$field => $value])));
        }

        return $model;
    }

    /**
     * @inheritDoc
     *
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): DataModel
    {
        $fields = $this->table->getFieldsForIdentity();

        if (Obj::implements($this->model, HasSingleIntIdentity::class)) {
            $attributes = $this->removeIdentifiableFields($attributes, $fields);
        } else {
            $this->maybeThrowForDuplicateIdentity($attributes, $fields);
        }

        $this->maybeThrowForDuplicateUniqueFieldsExcluding($attributes);

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
        $this->invalidateAfterWrite();

        // Single-record broadcasts intentionally PROPAGATE listener
        // exceptions (the pre-PR contract): there are no sibling events to
        // protect, unlike deleteWhere's buffered bulk emission.
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
     * @return void
     * @throws DatastoreErrorException
     */
    public function deleteWhere(array $conditions): void
    {
        $generation = $this->snapshotGeneration();

        try {
            $identityRows = $this->findIds([['type' => 'AND', 'clauses' => $conditions]]);
        } catch (RecordNotFoundException $e) {
            return;
        }
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

                $identity = $this->deriveRowIdentity($identityRow);

                if ($identity !== null) {
                    // deleteRowEntry() swallows-and-logs: cache trouble
                    // cannot abort the remaining SQL deletes.
                    $this->deleteRowEntry($identity, $generation);
                }

                $broadcastQueue[] = $identityRow;
                $deleted = true;
            }
        } finally {
            if ($deleted) {
                $this->invalidateAfterWrite();
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
                        ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
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
        $this->serviceProvider->clauseBuilder->reset()->useTable($this->table);
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
     * Lazily builds the adapter that converts rows, identities, and lookup
     * keys into the cache-context vocabulary. Lazy `??=` because a trait
     * cannot extend its consumer's constructor, and the adapter derives
     * entirely from $table/$model/$modelAdapter, which consumers set after
     * construction.
     */
    protected function getCacheContextAdapter(): RowCacheContextAdapter
    {
        return $this->rowCacheContextAdapter ??= new RowCacheContextAdapter(
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
     * set-level caches plus rotated aliases fall to precise handling (the
     * set-level delete in invalidateAfterWrite() and the read-time lookup
     * verification in findFromCompound()) instead of being orphaned
     * wholesale by the bump.
     */
    protected function shouldUseTableGenerations(): bool
    {
        return true;
    }

    /**
     * Derives the canonical identity from row data, logging when the row
     * cannot produce one (the adapter conversion itself is pure).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    protected function deriveRowIdentity(array $row): ?array
    {
        $identity = $this->getCacheContextAdapter()->toRowIdentity($row);

        if ($identity === null) {
            $this->serviceProvider->loggerStrategy->warning(
                'Cannot derive a canonical cache identity — row is missing an identity field.',
                ['table' => $this->table->getName(), 'rowFields' => array_keys($row)]
            );
        }

        return $identity;
    }

    /**
     * Mints an opaque, unique generation token — creation lives with the
     * orchestration; the adapter only knows token FORMATS.
     */
    protected function mintGenerationToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Takes the generation snapshot an operation keys its contexts under —
     * once, before any database query. One snapshot per operation is both
     * the race fence (a stale write-back keyed pre-write can never collide
     * with post-bump reader keys) and the round-trip bound. Null when
     * generations are disabled.
     */
    protected function snapshotGeneration(): ?string
    {
        return $this->getCacheContextAdapter()->usesGenerations() ? $this->currentGeneration() : null;
    }

    /**
     * Reads the current generation token, minting one when absent.
     *
     * Deliberately NOT getWithCache(): a policy whose shouldCache() declines
     * this context would re-mint a token on every read, silently defeating
     * generation stability. The get-then-set is last-write-wins and safe:
     * every minter SETs before its DB read and tokens are random, so
     * concurrent re-mints can only orphan each other's fresh entries, never
     * revive a stale one.
     */
    protected function currentGeneration(): string
    {
        $context = $this->getCacheContextAdapter()->toGenerationContext();

        try {
            $token = $this->serviceProvider->cacheableService->get($context);
        } catch (CachedItemNotFoundException $e) {
            $token = null;
        } catch (Throwable $e) {
            // A read FAILURE (not a miss) mints an ephemeral token for this
            // operation only, WITHOUT persisting: if reads blip while writes
            // still work, persisting would let every reader clobber a
            // healthy token and wholesale-invalidate the table cache.
            $this->serviceProvider->loggerStrategy->warning(
                'Generation token read failed — using an ephemeral token for this operation.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );

            return $this->getCacheContextAdapter()->toEphemeralGeneration($this->mintGenerationToken());
        }

        if (is_string($token) && $this->getCacheContextAdapter()->isValidGeneration($token)) {
            return $token;
        }

        $token = $this->mintGenerationToken();

        try {
            $this->serviceProvider->cacheableService->set($context, $token);
        } catch (Throwable $e) {
            // Cache down: every operation mints its own token, so keys
            // never match and reads fall through to the database —
            // caching degrades to disabled instead of breaking reads.
            $this->serviceProvider->loggerStrategy->warning(
                'Could not persist a table generation token — caching is effectively disabled until the cache recovers.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );
        }

        return $token;
    }

    /**
     * The one post-write invalidation step: bumps the generation when
     * generations are on (orphaning every prior context for the table at
     * once), precisely deletes the set-level context when they are off.
     * Never throws — a cache failure after a committed database write must
     * not fail the write, mask an in-flight exception, or suppress event
     * broadcasts.
     *
     * @return string|null The fresh generation token, or null when
     *                     generations are disabled or the cache failed.
     */
    protected function invalidateAfterWrite(): ?string
    {
        try {
            if (!$this->getCacheContextAdapter()->usesGenerations()) {
                $this->serviceProvider->cacheableService->delete($this->getCacheContextAdapter()->toTableContext(null));

                return null;
            }

            $token = $this->mintGenerationToken();
            $this->serviceProvider->cacheableService->set($this->getCacheContextAdapter()->toGenerationContext(), $token);

            return $token;
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->error(
                'Post-write cache invalidation failed — cached rows may serve stale data until TTL.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );

            return null;
        }
    }

    /**
     * Read-through for a canonical row entry. Under an ephemeral generation
     * snapshot the cache is skipped entirely — no key under such a token can
     * ever be read back.
     *
     * @param array<string, mixed> $identity Canonical identity (from deriveRowIdentity()).
     * @param string|null $generation Pre-query generation snapshot.
     * @param callable $fallback Loads the model on miss; its result is cached.
     * @return mixed
     */
    protected function readRowThrough(array $identity, ?string $generation, callable $fallback)
    {
        if ($this->getCacheContextAdapter()->isEphemeralGeneration($generation)) {
            return $fallback();
        }

        return $this->guardedReadThrough($this->getCacheContextAdapter()->toIdentityContext($identity, $generation), $fallback);
    }

    /**
     * Read-through for the table's single set-level value (estimatedCount).
     *
     * @param callable $fallback
     * @return mixed
     */
    protected function readTableValueThrough(callable $fallback)
    {
        $generation = $this->snapshotGeneration();

        if ($this->getCacheContextAdapter()->isEphemeralGeneration($generation)) {
            return $fallback();
        }

        return $this->guardedReadThrough($this->getCacheContextAdapter()->toTableContext($generation), $fallback);
    }

    /**
     * Read-through that survives a failing cache backend without masking
     * domain exceptions. Three outcomes, distinguished by where the failure
     * happened relative to the fallback: fallback loaded then the store
     * threw → serve the loaded value; the fallback itself threw → propagate
     * untouched (domain errors like RecordNotFoundException); the cache
     * probe threw before the fallback ran → load directly.
     *
     * @param array<string, mixed> $context
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
            return $this->serviceProvider->cacheableService->getWithCache(Operation::Read, $context, $capturing);
        } catch (Throwable $e) {
            if ($resolved) {
                $this->serviceProvider->loggerStrategy->warning(
                    'Cache store failed after a successful load — serving the loaded value uncached.',
                    ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
                );

                return $value;
            }

            if ($started) {
                throw $e;
            }

            // An entry evicted between the service's exists() and get() is a
            // normal miss under LRU pressure, not a failing backend — no log.
            if ($e instanceof CachedItemNotFoundException) {
                return $fallback();
            }

            $this->serviceProvider->loggerStrategy->warning(
                'Cache read failed — loading directly from the fallback.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );

            return $fallback();
        }
    }

    /**
     * Whether a row entry exists for the given identity row. A probe failure
     * reads as uncached — the caller falls through to the database.
     *
     * @param array<string, mixed> $identityRow
     * @param string|null $generation Pre-query generation snapshot.
     */
    protected function hasRowEntry(array $identityRow, ?string $generation): bool
    {
        $context = $this->getCacheContextAdapter()->toRowContext($identityRow, $generation);

        if ($context === null) {
            return false;
        }

        try {
            return $this->serviceProvider->cacheableService->exists($context);
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->warning(
                'Row cache probe failed — treating the row as uncached.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );

            return false;
        }
    }

    /**
     * Caches a row's model under its canonical context. No-ops under an
     * ephemeral snapshot; swallows-and-logs cache failures — read paths must
     * pass the snapshot they queried under (a token fetched at store time
     * can postdate a concurrent write's bump and reopen the race).
     *
     * @param array<string, mixed> $row
     * @param DataModel $model
     * @param string|null $generation Pre-query generation snapshot.
     */
    protected function storeRowEntry(array $row, DataModel $model, ?string $generation): void
    {
        if ($this->getCacheContextAdapter()->isEphemeralGeneration($generation)) {
            return;
        }

        $identity = $this->deriveRowIdentity($row);

        if ($identity === null) {
            return;
        }

        try {
            $this->serviceProvider->cacheableService->set($this->getCacheContextAdapter()->toIdentityContext($identity, $generation), $model);
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->warning(
                'Could not cache a row — the next read will hit the database.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );
        }
    }

    /**
     * Stores an alias entry pointing a business key at a canonical identity.
     * No-ops under an ephemeral snapshot; swallows-and-logs failures.
     *
     * @param array<string, mixed> $ids
     * @param array<string, mixed> $identity
     * @param string|null $generation Pre-query generation snapshot.
     */
    protected function storeAliasEntry(array $ids, array $identity, ?string $generation): void
    {
        if ($this->getCacheContextAdapter()->isEphemeralGeneration($generation)) {
            return;
        }

        try {
            $this->serviceProvider->cacheableService->set($this->getCacheContextAdapter()->toAliasContext($ids, $generation), $identity);
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->warning(
                'Could not cache an alias — the next lookup will re-resolve from the database.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );
        }
    }

    /**
     * Deletes the row entry for a canonical identity; swallows-and-logs.
     *
     * @param array<string, mixed> $identity
     * @param string|null $generation Pre-query generation snapshot.
     */
    protected function deleteRowEntry(array $identity, ?string $generation): void
    {
        try {
            $this->serviceProvider->cacheableService->delete($this->getCacheContextAdapter()->toIdentityContext($identity, $generation));
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->error(
                'Could not delete a cached row — it may serve stale data until the generation bump or TTL.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );
        }
    }

    /**
     * Deletes an alias entry; swallows-and-logs.
     *
     * @param array<string, mixed> $ids
     * @param string|null $generation Pre-query generation snapshot.
     */
    protected function deleteAliasEntry(array $ids, ?string $generation): void
    {
        try {
            $this->serviceProvider->cacheableService->delete($this->getCacheContextAdapter()->toAliasContext($ids, $generation));
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->error(
                'Could not delete a cached alias — it may serve a stale identity until the generation bump or TTL.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );
        }
    }

    /**
     * Reads the identity an alias entry points at, validated against the
     * table's identity shape. Null on miss, malformed value, or a
     * cache-layer read failure (logged).
     *
     * @param array<string, mixed> $ids
     * @param string|null $generation Pre-query generation snapshot.
     * @return array<string, mixed>|null
     */
    protected function resolveAliasedIdentity(array $ids, ?string $generation): ?array
    {
        try {
            $aliased = $this->serviceProvider->cacheableService->get($this->getCacheContextAdapter()->toAliasContext($ids, $generation));
        } catch (CachedItemNotFoundException $e) {
            return null;
        } catch (Throwable $e) {
            $this->serviceProvider->loggerStrategy->warning(
                'Alias cache read failed — treating the alias as missing.',
                ['table' => $this->table->getName(), 'exceptionClass' => get_class($e), 'exceptionMessage' => $e->getMessage()]
            );

            return null;
        }

        return (is_array($aliased) && $this->getCacheContextAdapter()->isTableIdentity($aliased)) ? $aliased : null;
    }


    /**
     * @param array<int, array<string, mixed>> $conditions
     * @param int|null $limit
     * @param int|null $offset
     * @return array<int, array<string, mixed>>
     * @throws DatastoreErrorException
     */
    public function findIds(array $conditions, ?int $limit = null, ?int $offset = null): array
    {
        // Same bootstrap as where(): initiateQuery()'s select defaults to
        // the identity fields, which is exactly this method's projection.
        $this->initiateQuery($limit, $offset)->buildConditions($conditions);

        return $this->serviceProvider->queryStrategy->query($this->serviceProvider->queryBuilder);
    }

    /**
     * Gets the models for the given identity rows, read-through cached.
     *
     * @param array<int, array<string, mixed>> $ids Identity rows from findIds() (values arrive DB-typed).
     * @return DataModel[]
     */
    protected function getModels(array $ids): array
    {
        // One generation snapshot for the whole operation, taken BEFORE any
        // database read — see storeRowEntry() for why this must not be
        // re-fetched at write-back time.
        $generation = $this->snapshotGeneration();

        // Filter out the items that are currently in the cache.
        $idsToQuery = Arr::filter(
            $ids,
            fn (array $identityRow) => !$this->hasRowEntry($identityRow, $generation)
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
                $key = $this->getCacheContextAdapter()->toIdentityKey($row);

                if ($key !== null) {
                    $hydrated[$key] = $model;
                    $this->storeRowEntry($row, $model, $generation);
                }
            }
        }

        // Return the rows in the requested order: just-hydrated models are
        // served directly; only ids skipped as already-cached consult the
        // cache (falling through to the database on a miss).
        $models = [];

        foreach ($ids as $id) {
            $key = $this->getCacheContextAdapter()->toIdentityKey($id);

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

        $generation = $generation ?? $this->snapshotGeneration();

        // Canonical lookup: the caller's key IS the table identity, so the
        // row entry can be addressed directly (after normalizing order/types).
        if ($this->getCacheContextAdapter()->isTableIdentity($ids)) {
            $identity = $this->deriveRowIdentity($ids);

            if ($identity !== null) {
                $model = $this->readRowThrough($identity, $generation, fn () => $this->queryRowAndModel($ids)[1]);

                // A cached value that is not a model (a poisoned or
                // old-format entry) must never be served — evict it and
                // repair the slot from the database, mirroring how the
                // alias path handles malformed entries.
                if ($model instanceof DataModel) {
                    return $model;
                }

                $this->deleteRowEntry($identity, $generation);

                [$freshRow, $freshModel] = $this->queryRowAndModel($ids);
                $this->storeRowEntry($freshRow, $freshModel, $generation);

                return $freshModel;
            }

            [, $freshModel] = $this->queryRowAndModel($ids);

            return $freshModel;
        }

        // Business-key lookup: resolve through an alias entry so the row is
        // still cached exactly once, under its canonical identity.
        $aliasedIdentity = $this->resolveAliasedIdentity($ids, $generation);

        if ($aliasedIdentity !== null) {
            try {
                $model = $this->findFromCompound($aliasedIdentity, $generation);

                // Verify the resolved row still matches the caller's lookup
                // values. An identity-keyed update can rotate a business key
                // out from under its alias, and on generation-disabled
                // tables nothing else would ever notice — the alias would
                // keep serving fresh-looking rows for a key they no longer
                // carry.
                $matches = $this->getCacheContextAdapter()->matchesLookup($model, $ids);

                if ($matches === null) {
                    // Unverifiable: the adapter exposes none of the lookup
                    // fields. With generations on, the bump covers rotation
                    // and the alias can be trusted; without them this check
                    // is the ONLY rotation defense, so the alias is stale.
                    if ($this->getCacheContextAdapter()->usesGenerations()) {
                        return $model;
                    }

                    $this->serviceProvider->loggerStrategy->warning(
                        'Alias lookup could not be verified — the model adapter exposes none of the lookup fields; treating the alias as stale.',
                        ['table' => $this->table->getName(), 'lookupFields' => array_keys($ids)]
                    );
                } elseif ($matches === true) {
                    return $model;
                }

                $this->deleteAliasEntry($ids, $generation);
            } catch (RecordNotFoundException $e) {
                // Stale alias — the row it points at moved or died. Drop it
                // and re-resolve from the database.
                $this->deleteAliasEntry($ids, $generation);
            }
        }

        [$row, $model] = $this->queryRowAndModel($ids);

        $identity = $this->deriveRowIdentity($row);

        if ($identity !== null) {
            $this->storeAliasEntry($ids, $identity, $generation);
            $this->storeRowEntry($row, $model, $generation);
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

        $item = $items[0] ?? null;

        if (!is_array($item) || $item === []) {
            throw new RecordNotFoundException(sprintf(
                'Record not found in table "%s" using lookup key %s.',
                $this->table->getName(),
                $this->encodeExceptionContext($ids)
            ));
        }

        return [$item, $this->modelAdapter->toModel($item)];
    }

    /**
     * @inheritDoc
     *
     * @param array<string, mixed> $ids
     * @param array<string, mixed> $attributes
     */
    public function updateCompound($ids, array $attributes): void
    {
        $generation = $this->snapshotGeneration();

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
        $this->maybeThrowForDuplicateUniqueFieldsExcluding($attributes, $this->deriveRowIdentity($identity), $record->getIdentity());

        // The SQL update targets the RESOLVED table identity AND the
        // caller's own lookup fields: the identity pins exactly one row (no
        // non-unique-business-key fan-out), and keeping the caller's fields
        // in the WHERE re-conditions the write on the key they asked for —
        // a concurrent rotation between resolution and UPDATE makes this a
        // no-op instead of updating a row that no longer carries the key.
        // (QueryStrategy::update() returns void, so a no-op write still
        // broadcasts; documented as a known limitation.)
        $conditions = $this->getCacheContextAdapter()->isTableIdentity($ids) ? $identity : Arr::merge($ids, $identity);
        $this->serviceProvider->queryStrategy->update($this->table, $conditions, $attributes);

        // The DB write is committed: the invalidation below is best-effort
        // (the entry mutations swallow-and-log cache failures) and runs under
        // the pre-write generation — the one readers wrote their entries
        // with. The bump closes the cache-aside race; precise deletes carry
        // tables that opt out of generations.
        $canonicalIdentity = $this->deriveRowIdentity($identity);

        if ($canonicalIdentity !== null) {
            $this->deleteRowEntry($canonicalIdentity, $generation);
        }

        if (!$this->getCacheContextAdapter()->isTableIdentity($ids)) {
            // Drop the alias too: the update may have moved the row's
            // business key or identity out from under it.
            $this->deleteAliasEntry($ids, $generation);
        }

        $this->invalidateAfterWrite();

        // The event intentionally carries the caller's key — the lookup
        // contract they wrote against — not the cache-normalized identity
        // the SQL targeted. Like create(), a single-record broadcast
        // propagates listener exceptions; only bulk emission isolates them.
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
        // Raw caller/row values are preferred: this identity feeds the SQL
        // WHERE, and cache-key stringification is cache vocabulary that
        // should not leak into driver-typed comparisons. (The cache delete
        // canonicalizes separately.)
        if ($this->getCacheContextAdapter()->isTableIdentity($ids)) {
            return $this->getCacheContextAdapter()->toRawIdentity($ids);
        }

        $aliased = $this->resolveAliasedIdentity($ids, $generation);

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

            return $this->getCacheContextAdapter()->toRawIdentity($row);
        } catch (RecordNotFoundException $e) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, string> $fields
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
     * @param array<string, mixed> $attributes
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    protected function removeIdentifiableFields(array $attributes, array $fields): array
    {
        return Arr::filter($attributes, fn($value, $fieldName) => !in_array($fieldName, $fields));
    }

    /**
     * Looks up records to check if a record with the specified unique columns already exists.
     *
     * @param array<string, mixed> $data
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
     * Guards unique-column groups. (Renamed from
     * maybeThrowForDuplicateUniqueFields when its second parameter changed
     * meaning, so stale call sites fail loudly instead of silently filtering
     * self-matches by the wrong identity.) When updating, the record being
     * updated is filtered out of the duplicate scan by TABLE identity — model
     * identities can be a subset of the table's and therefore shared across
     * distinct rows, so a model-identity self-match could hide a true
     * duplicate. The model-identity comparison is only the fallback for
     * adapters that cannot expose the table identity (that fallback CAN
     * shadow a duplicate sharing the model identity; such adapters trade
     * that for not tripping spurious self-duplicates).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $updateTableIdentity Canonical identity of the record being updated.
     * @param array<string, mixed>|null $updateModelIdentity Model identity of the record being updated.
     * @return void
     * @throws DuplicateEntryException
     * @throws DatastoreErrorException
     */
    protected function maybeThrowForDuplicateUniqueFieldsExcluding(array $data, ?array $updateTableIdentity = null, ?array $updateModelIdentity = null): void
    {
        try {
            $duplicates = $this->getDuplicates($data);

            if ($updateTableIdentity !== null || $updateModelIdentity !== null) {
                $duplicates = Arr::filter($duplicates, function (CanIdentify $existingItem) use ($updateTableIdentity, $updateModelIdentity) {
                    $existingTableIdentity = $existingItem instanceof DataModel
                        ? $this->deriveRowIdentity($this->modelAdapter->toArray($existingItem))
                        : null;

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
