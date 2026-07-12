<?php

namespace PHPNomad\Database\Tests\Unit\Traits;

use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\Doubles\NoopClauseBuilder;
use PHPNomad\Database\Tests\Doubles\NoopQueryBuilder;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
use PHPNomad\Events\Interfaces\Event;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

/**
 * End-to-end (through a REAL CacheableService with a live array-backed
 * strategy) proof of the canonical row cache design:
 *
 *  - a row has exactly ONE cache entry, keyed by its table identity derived
 *    from row data — never by the model's getIdentity(), never by the shape
 *    of the caller's question;
 *  - business-key lookups resolve through alias entries (pointer to the
 *    canonical identity), so writers can always name the keys readers used;
 *  - stale aliases self-heal;
 *  - per-table generation tokens make a late stale write-back unreachable
 *    without transactions.
 *
 * The cache policy used here hashes with md5(serialize($context)) — an
 * ORDER-SENSITIVE function — on purpose: it proves the contexts the trait
 * builds are canonical by construction, not rescued by a normalizing policy.
 */
class CanonicalRowCacheTest extends TestCase
{
    private ArrayCacheStrategy $cacheStrategy;
    private CacheableService $cacheableService;
    private ScriptedQueryStrategy $queryStrategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheStrategy = new ArrayCacheStrategy();
        $this->cacheableService = new CacheableService(
            new NullEventStrategy(),
            $this->cacheStrategy,
            new SerializingCachePolicy()
        );
        $this->queryStrategy = new ScriptedQueryStrategy();
    }

    private function makeHandler(
        array $identityFields,
        string $tableName = 'test_records',
        bool $useGenerations = false,
        ?LoggerStrategy $logger = null
    ): CanonicalHandler {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn($tableName);
        $table->method('getFieldsForIdentity')->willReturn($identityFields);
        $table->method('getColumns')->willReturn([]);

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->method('getUniqueColumns')->willReturn([]);

        $serviceProvider = new DatabaseServiceProvider(
            $logger ?? $this->createMock(LoggerStrategy::class),
            $this->queryStrategy,
            new NoopQueryBuilder(),
            new NoopClauseBuilder(),
            $this->cacheableService,
            new NullEventStrategy()
        );

        return new CanonicalHandler(
            $serviceProvider,
            $table,
            $tableSchemaService,
            AttrModel::class,
            new ArrayModelAdapter(),
            $useGenerations
        );
    }

    public function testWhereCachesRowsUnderTableIdentityNotModelIdentity(): void
    {
        $handler = $this->makeHandler(['orgId', 'id']);

        // findIds → identity rows; then SELECT * for the uncached row.
        $this->queryStrategy->queueQueryResult([['orgId' => '1', 'id' => '42']]);
        $this->queryStrategy->queueQueryResult([['orgId' => 1, 'id' => 42, 'name' => 'first']]);

        $models = $handler->where([['type' => 'AND', 'clauses' => [['column' => 'name', 'operator' => '=', 'value' => 'first']]]]);

        $this->assertCount(1, $models);

        // Cached under the TABLE identity (orgId + id, stringified, table order)…
        $this->assertTrue(
            $this->cacheableService->exists($handler->exposeRowContext(['orgId' => 1, 'id' => 42])),
            'Row is not cached under its canonical table-identity context.'
        );
        // …and NOT under the model's own (subset) identity.
        $this->assertFalse(
            $this->cacheableService->exists(['type' => AttrModel::class, 'identities' => ['id' => '42']]),
            'Row leaked a cache entry keyed by the MODEL identity.'
        );

        // A second identical read: findIds queries again (list SQL is not
        // cached), but the row itself is served from cache — no SELECT *.
        $this->queryStrategy->queueQueryResult([['orgId' => '1', 'id' => '42']]);
        $handler->where([['type' => 'AND', 'clauses' => [['column' => 'name', 'operator' => '=', 'value' => 'first']]]]);

        $this->assertSame(3, $this->queryStrategy->queryCount, 'Cached row was re-queried.');
    }

    public function testUpdateByBusinessKeyInvalidatesTheCanonicalRowEntry(): void
    {
        $handler = $this->makeHandler(['id'], 'test_api_keys');

        // Prime the row cache the way production reads do (list hydration).
        $this->queryStrategy->queueQueryResult([['id' => '7']]);
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $handler->where([['type' => 'AND', 'clauses' => [['column' => 'keyHash', 'operator' => '=', 'value' => 'abc']]]]);

        // Update by BUSINESS KEY (not the table identity). The pre-read
        // resolves the row (one query), then the write must invalidate the
        // id-keyed entry the read above created.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $handler->updateCompound(['keyHash' => 'abc'], ['status' => 'revoked']);

        $this->assertSame([['keyHash' => 'abc'], ['status' => 'revoked']], $this->queryStrategy->updates[0]);
        $this->assertFalse(
            $this->cacheableService->exists($handler->exposeRowContext(['id' => '7'])),
            'Business-key update left the canonical row entry to serve stale reads.'
        );

        // The next read round-trips the DB and sees the new value.
        $this->queryStrategy->queueQueryResult([['id' => '7']]);
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'revoked']]);
        $models = $handler->where([['type' => 'AND', 'clauses' => [['column' => 'keyHash', 'operator' => '=', 'value' => 'abc']]]]);

        $this->assertSame('revoked', $models[0]->get('status'));
    }

    public function testBusinessKeyLookupIsServedByAliasWithoutRequery(): void
    {
        $handler = $this->makeHandler(['id']);

        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);

        $first = $handler->findByCompound(['keyHash' => 'abc']);
        $second = $handler->findByCompound(['keyHash' => 'abc']);

        $this->assertSame('7', $first->get('id'));
        $this->assertSame('7', $second->get('id'));
        $this->assertSame(1, $this->queryStrategy->queryCount, 'Alias-resolved lookup hit the database again.');
    }

    public function testStaleAliasSelfHeals(): void
    {
        $handler = $this->makeHandler(['id']);

        // Seed: keyHash abc → id 7.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $handler->findByCompound(['keyHash' => 'abc']);

        // The row dies out from under the alias (deleted / re-keyed outside
        // this process). Drop the row entry to simulate; the alias remains.
        $this->cacheableService->delete($handler->exposeRowContext(['id' => '7']));

        // Alias → id 7 → miss → DB says id 7 is gone…
        $this->queryStrategy->queueQueryResult([]);
        // …so the alias is dropped and the business key re-resolves to id 9.
        $this->queryStrategy->queueQueryResult([['id' => '9', 'keyHash' => 'abc', 'status' => 'active']]);

        $model = $handler->findByCompound(['keyHash' => 'abc']);

        $this->assertSame('9', $model->get('id'), 'Stale alias did not self-heal.');
    }

    public function testHealedAliasServesNextLookupWithoutQuery(): void
    {
        $handler = $this->makeHandler(['id']);

        // Same healing sequence as testStaleAliasSelfHeals…
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $handler->findByCompound(['keyHash' => 'abc']);
        $this->cacheableService->delete($handler->exposeRowContext(['id' => '7']));
        $this->queryStrategy->queueQueryResult([]);
        $this->queryStrategy->queueQueryResult([['id' => '9', 'keyHash' => 'abc', 'status' => 'active']]);
        $handler->findByCompound(['keyHash' => 'abc']);

        // …then the healed alias must serve the follow-up lookup query-free.
        $before = $this->queryStrategy->queryCount;
        $handler->findByCompound(['keyHash' => 'abc']);

        $this->assertSame($before, $this->queryStrategy->queryCount);
    }

    public function testGenerationBumpMakesLateStaleWriteUnreachable(): void
    {
        $handler = $this->makeHandler(['id'], 'test_records', true);

        // Reader caches the row under the current generation.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'status' => 'active']]);
        $stale = $handler->findByCompound(['id' => '7']);

        // A slow reader computed its context BEFORE the write…
        $staleContext = $handler->exposeRowContext(['id' => '7']);

        // …the writer updates and bumps the generation (its pre-read is
        // served from the cache — no query)…
        $handler->updateCompound(['id' => '7'], ['status' => 'revoked']);

        // …and the slow reader SETs its stale row back using the OLD context.
        $this->cacheableService->set($staleContext, $stale);

        // The stale entry physically exists, but no new reader can compute
        // its key: the next read misses and round-trips the DB.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'status' => 'revoked']]);
        $fresh = $handler->findByCompound(['id' => '7']);

        $this->assertSame('revoked', $fresh->get('status'), 'Late stale write-back was served after the generation bump.');
    }

    public function testEstimatedCountInvalidatesAfterWrite(): void
    {
        $handler = $this->makeHandler(['id'], 'test_records', true);

        $this->queryStrategy->estimatedCountValue = 5;
        $this->assertSame(5, $handler->getEstimatedCount());

        // Cached: a changed underlying count is not visible yet.
        $this->queryStrategy->estimatedCountValue = 6;
        $this->assertSame(5, $handler->getEstimatedCount());

        // Any write bumps the table generation, orphaning the cached count.
        $handler->create(['name' => 'new row']);

        $this->assertSame(6, $handler->getEstimatedCount(), 'estimatedCount survived a write — generation did not invalidate it.');
    }

    public function testRowMissingAnIdentityFieldIsNotCachedAndWarns(): void
    {
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('missing an identity field'), $this->arrayHasKey('missingField'));

        $handler = $this->makeHandler(['orgId', 'id'], 'test_records', false, $logger);

        // The SELECT * row lacks orgId — a partial identity must never
        // become a cache key.
        $this->queryStrategy->queueQueryResult([['id' => '42']]);
        $this->queryStrategy->queueQueryResult([['id' => 42, 'name' => 'incomplete']]);
        // The read-back cannot be served from cache, so it queries again.
        $this->queryStrategy->queueQueryResult([['id' => 42, 'name' => 'incomplete']]);

        $handler->where([['type' => 'AND', 'clauses' => [['column' => 'name', 'operator' => '=', 'value' => 'incomplete']]]]);

        $this->assertSame([], $this->cacheStrategy->store, 'A partial-identity row produced a cache entry.');
    }
}

class CanonicalHandler
{
    use WithDatastoreHandlerMethods;

    private bool $useGenerations;

    public function __construct(
        DatabaseServiceProvider $serviceProvider,
        Table $table,
        TableSchemaService $tableSchemaService,
        string $model,
        ModelAdapter $modelAdapter,
        bool $useGenerations = false
    ) {
        $this->serviceProvider = $serviceProvider;
        $this->table = $table;
        $this->tableSchemaService = $tableSchemaService;
        $this->model = $model;
        $this->modelAdapter = $modelAdapter;
        $this->useGenerations = $useGenerations;
    }

    protected function shouldUseTableGenerations(): bool
    {
        return $this->useGenerations;
    }

    public function findByCompound(array $ids)
    {
        return $this->findFromCompound($ids);
    }

    public function exposeRowContext(array $row): ?array
    {
        return $this->getCanonicalRowContext($row);
    }
}

/**
 * Model whose own identity is deliberately a SUBSET of the table identity
 * (like a model that omits a tenant column) — the trait must never key the
 * cache off it.
 */
class AttrModel implements DataModel
{
    public function __construct(private array $row = [])
    {
    }

    public function get(string $field)
    {
        return $this->row[$field] ?? null;
    }

    public function getIdentity(): array
    {
        return ['id' => $this->row['id'] ?? null];
    }
}

class ArrayModelAdapter implements ModelAdapter
{
    public function toModel(array $array): DataModel
    {
        return new AttrModel($array);
    }

    public function toArray(DataModel $model): array
    {
        return [];
    }
}

class ScriptedQueryStrategy implements QueryStrategy
{
    /** @var array[] */
    private array $queryResults = [];
    public int $queryCount = 0;
    public int $estimatedCountValue = 0;
    /** @var array[] */
    public array $updates = [];
    /** @var array[] */
    public array $deletes = [];

    public function queueQueryResult(array $result): void
    {
        $this->queryResults[] = $result;
    }

    public function query(QueryBuilder $builder): array
    {
        $this->queryCount++;

        if (empty($this->queryResults)) {
            throw new \LogicException('ScriptedQueryStrategy ran out of queued query results — unexpected query #' . $this->queryCount);
        }

        return array_shift($this->queryResults);
    }

    public function insert(Table $table, array $data): array
    {
        return ['id' => 1];
    }

    public function delete(Table $table, array $ids): void
    {
        $this->deletes[] = $ids;
    }

    public function update(Table $table, array $ids, array $data): void
    {
        $this->updates[] = [$ids, $data];
    }

    public function estimatedCount(Table $table): int
    {
        return $this->estimatedCountValue;
    }
}

class ArrayCacheStrategy implements CacheStrategy
{
    /** @var array<string, mixed> */
    public array $store = [];

    public function get(string $key)
    {
        if (!array_key_exists($key, $this->store)) {
            throw new CachedItemNotFoundException('No cached item found for key ' . $key);
        }

        return $this->store[$key];
    }

    public function set(string $key, $value, ?int $ttl): void
    {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function clear(): void
    {
        $this->store = [];
    }
}

/**
 * Deliberately order-sensitive key function: proves the trait's contexts are
 * canonical by construction rather than rescued by a normalizing policy.
 */
class SerializingCachePolicy implements CachePolicy
{
    public function shouldCache(string $operation, array $context = []): bool
    {
        return true;
    }

    public function getCacheKey(array $context): string
    {
        return md5(serialize($context));
    }

    public function getTtl(array $context = []): ?int
    {
        return 60;
    }

    public function shouldInvalidate(string $operation, array $context = []): bool
    {
        return true;
    }
}

class NullEventStrategy implements EventStrategy
{
    public function broadcast(Event $event): void
    {
    }

    public function attach(string $event, callable $action, ?int $priority = null): void
    {
    }

    public function detach(string $event, callable $action, ?int $priority = null): void
    {
    }
}
