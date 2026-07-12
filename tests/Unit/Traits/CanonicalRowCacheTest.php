<?php

namespace PHPNomad\Database\Tests\Unit\Traits;

use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\Doubles\ArrayCacheStrategy;
use PHPNomad\Database\Tests\Doubles\NoopClauseBuilder;
use PHPNomad\Database\Tests\Doubles\NoopQueryBuilder;
use PHPNomad\Database\Tests\Doubles\NullEventStrategy;
use PHPNomad\Database\Tests\Doubles\RecordingEventStrategy;
use PHPNomad\Database\Tests\Doubles\ScriptedQueryStrategy;
use PHPNomad\Database\Tests\Doubles\SerializingCachePolicy;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;
use PHPNomad\Datastore\Events\RecordDeleted;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
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
 *  - stale or rotated aliases self-heal;
 *  - per-table generation tokens make a late stale write-back unreachable
 *    without transactions;
 *  - deletes remove rows by full table identity and always broadcast.
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
        ?LoggerStrategy $logger = null,
        ?EventStrategy $events = null
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
            $events ?? new NullEventStrategy()
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

    /**
     * Both generation modes must exhibit identical canonical-keying behavior;
     * generations only change WHICH key a context hashes to, never the
     * invariants. (Production default is generations ON.)
     *
     * @return array<string, array{0: bool}>
     */
    public function generationModes(): array
    {
        return [
            'generations on (production default)' => [true],
            'generations off (opt-out)' => [false],
        ];
    }

    /**
     * @dataProvider generationModes
     */
    public function testWhereCachesRowsUnderTableIdentityNotModelIdentity(bool $useGenerations): void
    {
        $handler = $this->makeHandler(['orgId', 'id'], 'test_records', $useGenerations);

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

    /**
     * @dataProvider generationModes
     */
    public function testUpdateByBusinessKeyInvalidatesTheCanonicalRowEntry(bool $useGenerations): void
    {
        $handler = $this->makeHandler(['id'], 'test_api_keys', $useGenerations);

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

    /**
     * @dataProvider generationModes
     */
    public function testBusinessKeyLookupIsServedByAliasWithoutRequery(bool $useGenerations): void
    {
        $handler = $this->makeHandler(['id'], 'test_records', $useGenerations);

        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);

        $first = $handler->findByCompound(['keyHash' => 'abc']);
        $second = $handler->findByCompound(['keyHash' => 'abc']);

        $this->assertSame('7', $first->get('id'));
        $this->assertSame('7', $second->get('id'));
        $this->assertSame(1, $this->queryStrategy->queryCount, 'Alias-resolved lookup hit the database again.');
    }

    /**
     * @dataProvider generationModes
     */
    public function testStaleAliasSelfHeals(bool $useGenerations): void
    {
        $handler = $this->makeHandler(['id'], 'test_records', $useGenerations);

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

    /**
     * @dataProvider generationModes
     */
    public function testHealedAliasServesNextLookupWithoutQuery(bool $useGenerations): void
    {
        $handler = $this->makeHandler(['id'], 'test_records', $useGenerations);

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

    public function testRotatedBusinessKeyAliasDoesNotServeTheOldKey(): void
    {
        // Generations OFF: without the bump, ONLY read-time verification
        // stands between a rotated business key and its orphaned alias
        // serving fresh-looking rows for a key they no longer carry.
        $handler = $this->makeHandler(['id'], 'test_api_keys', false);

        // Seed alias: keyHash abc → id 7.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $handler->findByCompound(['keyHash' => 'abc']);

        // Rotate the key via an identity-keyed update — the alias for 'abc'
        // is not directly addressable from ['id' => '7'].
        $handler->updateCompound(['id' => '7'], ['keyHash' => 'xyz']);

        // Lookup by the OLD key: alias → id 7 → row entry was invalidated →
        // DB re-read returns the rotated row (keyHash xyz) → verification
        // rejects it → alias dropped → re-resolve by keyHash finds nothing.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'xyz', 'status' => 'active']]);
        $this->queryStrategy->queueQueryResult([]);

        $this->expectException(RecordNotFoundException::class);

        $handler->findByCompound(['keyHash' => 'abc']);
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

    public function testEstimatedCountInvalidatesAfterWriteWithoutGenerations(): void
    {
        // Opt-out tables have no generation bump; writes must delete the
        // set-level context precisely instead.
        $handler = $this->makeHandler(['id'], 'test_records', false);

        $this->queryStrategy->estimatedCountValue = 5;
        $this->assertSame(5, $handler->getEstimatedCount());

        $this->queryStrategy->estimatedCountValue = 6;
        $this->assertSame(5, $handler->getEstimatedCount());

        $handler->create(['name' => 'new row']);

        $this->assertSame(6, $handler->getEstimatedCount(), 'estimatedCount survived a write on a generation-disabled table.');
    }

    public function testCreatePreWarmsRowReadableWithoutQuery(): void
    {
        // Bump-then-pre-warm ordering: the created row's entry must land
        // under the NEW generation, or every post-create read would miss.
        $handler = $this->makeHandler(['id'], 'test_records', true);

        $created = $handler->create(['name' => 'warm']);

        // No query result is queued: a DB round-trip here would throw.
        $read = $handler->findByCompound(['id' => 1]);

        $this->assertSame('warm', $read->get('name'));
        $this->assertSame(0, $this->queryStrategy->queryCount);
        $this->assertSame($created, $read);
    }

    /**
     * Cache-entry expectations differ only by the generation-token entry the
     * ON mode keeps in the store.
     *
     * @return array<string, array{0: bool, 1: int}>
     */
    public function generationModesWithExpectedEntries(): array
    {
        return [
            'generations on (production default)' => [true, 1],
            'generations off (opt-out)' => [false, 0],
        ];
    }

    /**
     * @dataProvider generationModesWithExpectedEntries
     */
    public function testRowMissingAnIdentityFieldIsNotCachedAndWarns(bool $useGenerations, int $expectedEntries): void
    {
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('missing an identity field'), $this->arrayHasKey('missingField'));

        $handler = $this->makeHandler(['orgId', 'id'], 'test_records', $useGenerations, $logger);

        // The SELECT * row lacks orgId — a partial identity must never
        // become a cache key.
        $this->queryStrategy->queueQueryResult([['id' => '42']]);
        $this->queryStrategy->queueQueryResult([['id' => 42, 'name' => 'incomplete']]);
        // The read-back cannot be served from cache, so it queries again.
        $this->queryStrategy->queueQueryResult([['id' => 42, 'name' => 'incomplete']]);

        $handler->where([['type' => 'AND', 'clauses' => [['column' => 'name', 'operator' => '=', 'value' => 'incomplete']]]]);

        // Only the generation token (when enabled) may exist — no row entry,
        // no alias entry.
        $this->assertCount($expectedEntries, $this->cacheStrategy->store, 'A partial-identity row produced a cache entry.');
    }

    /**
     * @dataProvider generationModes
     */
    public function testDeleteWhereDeletesByTableIdentityAndInvalidates(bool $useGenerations): void
    {
        $events = new RecordingEventStrategy();
        $handler = $this->makeHandler(['orgId', 'id'], 'test_records', $useGenerations, null, $events);

        // Prime the cache.
        $this->queryStrategy->queueQueryResult([['orgId' => '1', 'id' => '42']]);
        $this->queryStrategy->queueQueryResult([['orgId' => '1', 'id' => '42', 'name' => 'doomed']]);
        $handler->where([['type' => 'AND', 'clauses' => [['column' => 'name', 'operator' => '=', 'value' => 'doomed']]]]);

        // deleteWhere resolves identity rows and deletes by the FULL table
        // identity — not the model's subset identity.
        $this->queryStrategy->queueQueryResult([['orgId' => '1', 'id' => '42']]);
        $handler->deleteWhere([['column' => 'name', 'operator' => '=', 'value' => 'doomed']]);

        $this->assertSame([['orgId' => '1', 'id' => '42']], $this->queryStrategy->deletes);
        $this->assertFalse(
            $this->cacheableService->exists($handler->exposeRowContext(['orgId' => '1', 'id' => '42'])),
            'deleteWhere left the canonical row entry behind.'
        );

        // The deletion is broadcast with the raw identity row — DB-typed
        // values, no cache normalization in the event contract.
        $deletions = array_filter($events->broadcasts, fn($event) => $event instanceof RecordDeleted);
        $this->assertCount(1, $deletions);
        $deletion = array_values($deletions)[0];
        $this->assertSame(AttrModel::class, $deletion->getType());
        $this->assertSame(['orgId' => '1', 'id' => '42'], $deletion->getIdentity());
    }

    public function testDeleteWhereBroadcastsWhenIdentityCannotBeDerived(): void
    {
        $events = new RecordingEventStrategy();
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->atLeastOnce())->method('warning');

        $handler = $this->makeHandler(['orgId', 'id'], 'test_records', false, $logger, $events);

        // The identity row is missing orgId, so no cache entry can be named
        // — but the SQL delete still runs and listeners MUST hear about it.
        $this->queryStrategy->queueQueryResult([['id' => '42']]);
        $handler->deleteWhere([['column' => 'name', 'operator' => '=', 'value' => 'orphan']]);

        $this->assertSame([['id' => '42']], $this->queryStrategy->deletes);

        $deletions = array_filter($events->broadcasts, fn($event) => $event instanceof RecordDeleted);
        $this->assertCount(1, $deletions);
        $this->assertSame(['id' => '42'], array_values($deletions)[0]->getIdentity());
    }

    public function testDeleteWhereWithNoMatchesLeavesCacheUntouched(): void
    {
        $handler = $this->makeHandler(['id'], 'test_records', true);

        // Prime a generation token + row entry via a read.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'status' => 'active']]);
        $handler->findByCompound(['id' => '7']);
        $storeBefore = $this->cacheStrategy->store;

        // No rows match: nothing may be deleted, bumped, or re-minted.
        $this->queryStrategy->queueQueryResult([]);
        $handler->deleteWhere([['column' => 'status', 'operator' => '=', 'value' => 'missing']]);

        $this->assertSame($storeBefore, $this->cacheStrategy->store);
        $this->assertSame([], $this->queryStrategy->deletes);
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
        return $this->rowCache()->rowContext($row);
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

    public function toRow(): array
    {
        return $this->row;
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
        return $model instanceof AttrModel ? $model->toRow() : [];
    }
}
