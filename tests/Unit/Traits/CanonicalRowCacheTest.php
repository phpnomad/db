<?php

namespace PHPNomad\Database\Tests\Unit\Traits;

use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Factories\DatastoreRowCacheFactory;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\Doubles\ArrayCacheStrategy;
use PHPNomad\Database\Tests\Doubles\ExposedRowCache;
use PHPNomad\Database\Tests\Doubles\FlakyCacheStrategy;
use PHPNomad\Database\Tests\Doubles\HidingModelAdapter;
use PHPNomad\Database\Tests\Doubles\IdentityRowModel;
use PHPNomad\Database\Tests\Doubles\IdentityRowModelAdapter;
use PHPNomad\Database\Tests\Doubles\NoopClauseBuilder;
use PHPNomad\Database\Tests\Doubles\NoopQueryBuilder;
use PHPNomad\Database\Tests\Doubles\NullEventStrategy;
use PHPNomad\Database\Tests\Doubles\RecordingEventStrategy;
use PHPNomad\Database\Tests\Doubles\ScriptedQueryStrategy;
use PHPNomad\Database\Tests\Doubles\SerializingCachePolicy;
use PHPNomad\Database\Tests\Doubles\ThrowingOnceEventStrategy;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;
use PHPNomad\Datastore\Events\RecordCreated;
use PHPNomad\Datastore\Events\RecordDeleted;
use PHPNomad\Datastore\Events\RecordUpdated;
use PHPNomad\Datastore\Exceptions\DuplicateEntryException;
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

    /**
     * Context prober mirroring the last-built handler's row-cache config;
     * computes the same cache slots (shared cache = shared generation token)
     * without widening the production RowCache contract.
     */
    private ExposedRowCache $prober;

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
        ?EventStrategy $events = null,
        ?ModelAdapter $adapter = null,
        array $uniqueColumns = []
    ): CanonicalHandler {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn($tableName);
        $table->method('getFieldsForIdentity')->willReturn($identityFields);
        $table->method('getColumns')->willReturn([]);

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->method('getUniqueColumns')->willReturn($uniqueColumns);

        $logger = $logger ?? $this->createMock(LoggerStrategy::class);

        $serviceProvider = new DatabaseServiceProvider(
            $logger,
            $this->queryStrategy,
            new NoopQueryBuilder(),
            new NoopClauseBuilder(),
            $this->cacheableService,
            $events ?? new NullEventStrategy(),
            new DatastoreRowCacheFactory($this->cacheableService, $logger)
        );

        $adapter = $adapter ?? new IdentityRowModelAdapter();

        $this->prober = new ExposedRowCache(
            $this->cacheableService,
            $logger,
            $table,
            IdentityRowModel::class,
            $adapter,
            $useGenerations
        );

        return new CanonicalHandler(
            $serviceProvider,
            $table,
            $tableSchemaService,
            IdentityRowModel::class,
            $adapter,
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
     * @dataProvider generationModesWithRowEntryCounts
     */
    public function testWhereCachesRowsUnderTableIdentityNotModelIdentity(bool $useGenerations, int $expectedEntries): void
    {
        $handler = $this->makeHandler(['orgId', 'id'], 'test_records', $useGenerations);

        // findIds → identity rows; then SELECT * for the uncached row.
        $this->queryStrategy->queueQueryResult([['orgId' => '1', 'id' => '42']]);
        $this->queryStrategy->queueQueryResult([['orgId' => 1, 'id' => 42, 'name' => 'first']]);

        $models = $handler->where([['type' => 'AND', 'clauses' => [['column' => 'name', 'operator' => '=', 'value' => 'first']]]]);

        $this->assertCount(1, $models);

        // Cached under the TABLE identity (orgId + id, stringified, table order)…
        $this->assertTrue(
            $this->cacheableService->exists($this->prober->exposeRowContext(['orgId' => 1, 'id' => 42])),
            'Row is not cached under its canonical table-identity context.'
        );
        // …and NOT under the model's own (subset) identity. The probed shape
        // mirrors the pre-PR key vocabulary as regression documentation; the
        // exact entry count below is the general guard — one row entry plus
        // the generation token when generations are on.
        $this->assertFalse(
            $this->cacheableService->exists(['type' => IdentityRowModel::class, 'identities' => ['id' => '42']]),
            'Row leaked a cache entry keyed by the MODEL identity.'
        );
        $this->assertCount($expectedEntries, $this->cacheStrategy->store, 'Unexpected cache entries beyond the row (and generation token).');

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

        // The SQL update targets the RESOLVED identity pinned together with
        // the caller's business key: the identity prevents non-unique-key
        // fan-out, and keeping the key in the WHERE turns a concurrent
        // rotation into a no-op instead of updating a row that no longer
        // carries the key.
        $this->assertSame([['keyHash' => 'abc', 'id' => '7'], ['status' => 'revoked']], $this->queryStrategy->updates[0]);
        $this->assertFalse(
            $this->cacheableService->exists($this->prober->exposeRowContext(['id' => '7'])),
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
     * Seeds keyHash abc → id 7, kills row 7 out from under the alias, and
     * scripts the re-resolution to id 9 — the shared healing sequence.
     */
    private function healRotatedAlias(CanonicalHandler $handler): DataModel
    {
        // Seed: keyHash abc → id 7.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $handler->findByCompound(['keyHash' => 'abc']);

        // The row dies out from under the alias (deleted / re-keyed outside
        // this process). Drop the row entry to simulate; the alias remains.
        $this->cacheableService->delete($this->prober->exposeRowContext(['id' => '7']));

        // Alias → id 7 → miss → DB says id 7 is gone…
        $this->queryStrategy->queueQueryResult([]);
        // …so the alias is dropped and the business key re-resolves to id 9.
        $this->queryStrategy->queueQueryResult([['id' => '9', 'keyHash' => 'abc', 'status' => 'active']]);

        return $handler->findByCompound(['keyHash' => 'abc']);
    }

    /**
     * @dataProvider generationModes
     */
    public function testStaleAliasSelfHeals(bool $useGenerations): void
    {
        $handler = $this->makeHandler(['id'], 'test_records', $useGenerations);

        $model = $this->healRotatedAlias($handler);

        $this->assertSame('9', $model->get('id'), 'Stale alias did not self-heal.');
    }

    /**
     * @dataProvider generationModes
     */
    public function testHealedAliasServesNextLookupWithoutQuery(bool $useGenerations): void
    {
        $handler = $this->makeHandler(['id'], 'test_records', $useGenerations);

        $this->healRotatedAlias($handler);

        // The healed alias must serve the follow-up lookup query-free.
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
        $staleContext = $this->prober->exposeRowContext(['id' => '7']);

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

    /**
     * Generations orphan the cached count via the bump; opt-out tables
     * delete the set-level context precisely. Same observable behavior.
     *
     * @dataProvider generationModes
     */
    public function testEstimatedCountInvalidatesAfterWrite(bool $useGenerations): void
    {
        $handler = $this->makeHandler(['id'], 'test_records', $useGenerations);

        $this->queryStrategy->estimatedCountValue = 5;
        $this->assertSame(5, $handler->getEstimatedCount());

        // Cached: a changed underlying count is not visible yet.
        $this->queryStrategy->estimatedCountValue = 6;
        $this->assertSame(5, $handler->getEstimatedCount());

        // Any write invalidates the cached count.
        $handler->create(['name' => 'new row']);

        $this->assertSame(6, $handler->getEstimatedCount(), 'estimatedCount survived a write.');
    }

    public function testFirstReadAfterCreateHitsTheDatabase(): void
    {
        // create() deliberately does NOT pre-warm: a model hydrated from
        // write attributes carries request-typed scalars, and a pre-warm can
        // seed the newest generation with a row another writer already
        // overwrote. The first read costs one round-trip and is DB-true.
        $handler = $this->makeHandler(['id'], 'test_records', true);

        $handler->create(['name' => 'as-written']);

        $this->queryStrategy->queueQueryResult([['id' => '1', 'name' => 'db-truth']]);
        $read = $handler->findByCompound(['id' => 1]);

        $this->assertSame('db-truth', $read->get('name'));
        $this->assertSame(1, $this->queryStrategy->queryCount);
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
     * One cached row plus the generation-token entry the ON mode keeps.
     *
     * @return array<string, array{0: bool, 1: int}>
     */
    public function generationModesWithRowEntryCounts(): array
    {
        return [
            'generations on (production default)' => [true, 2],
            'generations off (opt-out)' => [false, 1],
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
            $this->cacheableService->exists($this->prober->exposeRowContext(['orgId' => '1', 'id' => '42'])),
            'deleteWhere left the canonical row entry behind.'
        );

        // The deletion is broadcast with the raw identity row — DB-typed
        // values, no cache normalization in the event contract.
        $deletions = $events->ofType(RecordDeleted::class);
        $this->assertCount(1, $deletions);
        $this->assertSame(IdentityRowModel::class, $deletions[0]->getType());
        $this->assertSame(['orgId' => '1', 'id' => '42'], $deletions[0]->getIdentity());
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

        $deletions = $events->ofType(RecordDeleted::class);
        $this->assertCount(1, $deletions);
        $this->assertSame(['id' => '42'], $deletions[0]->getIdentity());
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
    private function useFlakyCache(): FlakyCacheStrategy
    {
        $flaky = new FlakyCacheStrategy();
        $this->cacheStrategy = $flaky;
        $this->cacheableService = new CacheableService(
            new NullEventStrategy(),
            $flaky,
            new SerializingCachePolicy()
        );

        return $flaky;
    }

    public function testCreateSurvivesCacheWriteFailureAndStillBroadcasts(): void
    {
        $flaky = $this->useFlakyCache();
        $events = new RecordingEventStrategy();
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->atLeastOnce())->method('error');

        $handler = $this->makeHandler(['id'], 'test_records', true, $logger, $events);

        $flaky->failWrites = true;

        $created = $handler->create(['name' => 'survivor']);

        $this->assertSame('survivor', $created->get('name'));
        $this->assertCount(1, $events->ofType(RecordCreated::class), 'A cache outage suppressed RecordCreated for a committed insert.');
    }

    public function testUpdateCompoundSurvivesCacheWriteFailureAndStillBroadcasts(): void
    {
        $flaky = $this->useFlakyCache();
        $events = new RecordingEventStrategy();
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->atLeastOnce())->method('error');

        $handler = $this->makeHandler(['id'], 'test_records', true, $logger, $events);

        // Prime the row while the cache is healthy so the pre-read hits.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'status' => 'active']]);
        $handler->findByCompound(['id' => '7']);

        $flaky->failWrites = true;

        $handler->updateCompound(['id' => '7'], ['status' => 'revoked']);

        $this->assertCount(1, $this->queryStrategy->updates, 'The committed update was rolled back by a cache failure.');
        $this->assertCount(1, $events->ofType(RecordUpdated::class), 'A cache outage suppressed RecordUpdated for a committed update.');
    }

    public function testDeleteWhereSurvivesCacheWriteFailureAndStillBroadcasts(): void
    {
        $flaky = $this->useFlakyCache();
        $events = new RecordingEventStrategy();
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->atLeastOnce())->method('error');

        $handler = $this->makeHandler(['id'], 'test_records', true, $logger, $events);

        // Prime a generation token while the cache is healthy.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'status' => 'doomed']]);
        $handler->findByCompound(['id' => '7']);

        $flaky->failWrites = true;

        $this->queryStrategy->queueQueryResult([['id' => '7']]);
        $handler->deleteWhere([['column' => 'status', 'operator' => '=', 'value' => 'doomed']]);

        $this->assertSame([['id' => '7']], $this->queryStrategy->deletes, 'A cache failure aborted the SQL delete.');
        $this->assertCount(1, $events->ofType(RecordDeleted::class), 'A cache outage suppressed RecordDeleted for a committed delete.');
    }

    public function testUnverifiableAliasLookupFallsBackToTheDatabaseWithoutGenerations(): void
    {
        // The adapter exposes none of the lookup fields, so alias-resolved
        // rows can't be verified. On a generation-disabled table the alias
        // must not be trusted: every lookup re-resolves from the database.
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->stringContains('could not be verified'));

        $handler = $this->makeHandler(['id'], 'test_records', false, $logger, null, new HidingModelAdapter());

        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $handler->findByCompound(['keyHash' => 'abc']);

        // Second lookup: alias hit, row hit, unverifiable, treated as
        // stale, re-resolved from the database.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $model = $handler->findByCompound(['keyHash' => 'abc']);

        $this->assertSame('7', $model->get('id'));
        $this->assertSame(2, $this->queryStrategy->queryCount, 'An unverifiable alias was trusted on a generation-disabled table.');
    }

    public function testColdBusinessKeyReadSurvivesCacheWriteFailure(): void
    {
        // The DB answered; a cache that cannot store the result must not
        // turn a successful read into an error.
        $flaky = $this->useFlakyCache();
        $logger = $this->createMock(LoggerStrategy::class);

        $handler = $this->makeHandler(['id'], 'test_records', true, $logger);

        $flaky->failWrites = true;

        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $model = $handler->findByCompound(['keyHash' => 'abc']);

        $this->assertSame('7', $model->get('id'));
    }

    public function testRotatedAliasLookupSurfacesNotFoundNotCacheErrorsUnderWriteFailure(): void
    {
        // The self-heal path performs cache mutations (row store, alias
        // delete); with a write-failing cache the lookup must still resolve
        // to its true outcome — RecordNotFoundException — not a cache error.
        $flaky = $this->useFlakyCache();

        $handler = $this->makeHandler(['id'], 'test_api_keys', false);

        // Prime alias keyHash abc → id 7 while healthy, then drop the row
        // entry so the next lookup takes the re-read path.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $handler->findByCompound(['keyHash' => 'abc']);
        $this->cacheableService->delete($this->prober->exposeRowContext(['id' => '7']));

        $flaky->failWrites = true;

        // Alias → id 7 → row re-read shows the key rotated away → alias
        // dropped (swallowed failure) → re-resolve by key finds nothing.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'xyz', 'status' => 'active']]);
        $this->queryStrategy->queueQueryResult([]);

        $this->expectException(RecordNotFoundException::class);

        $handler->findByCompound(['keyHash' => 'abc']);
    }

    public function testUpdateCompoundThrowsWhenTheRecordCannotBeReResolved(): void
    {
        // With a write-dead cache the alias never persists, and the DB
        // fallback resolution comes back empty (the row vanished
        // mid-operation). Falling back to the caller's business key could
        // fan the write out — the update must fail instead.
        $flaky = $this->useFlakyCache();

        $handler = $this->makeHandler(['id'], 'test_api_keys', true);

        $flaky->failWrites = true;

        // Pre-read resolves via the DB (alias store fails silently)…
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        // …then identity resolution's DB fallback finds the row gone.
        $this->queryStrategy->queueQueryResult([]);

        try {
            $handler->updateCompound(['keyHash' => 'abc'], ['status' => 'revoked']);
            $this->fail('Expected RecordNotFoundException when the record cannot be re-resolved.');
        } catch (RecordNotFoundException $e) {
            $this->assertSame([], $this->queryStrategy->updates, 'An unresolvable record was still updated by raw business key.');
        }
    }

    public function testListReadStaysBatchedWhenCacheWritesFail(): void
    {
        // The batch result must not depend on the cache write landing: with
        // a write-dead cache, one where() is still findIds + one SELECT —
        // never 1+N per-row re-queries.
        $flaky = $this->useFlakyCache();

        $handler = $this->makeHandler(['id'], 'test_records', true);

        $flaky->failWrites = true;

        $this->queryStrategy->queueQueryResult([['id' => '1'], ['id' => '2']]);
        $this->queryStrategy->queueQueryResult([
            ['id' => '1', 'name' => 'first'],
            ['id' => '2', 'name' => 'second'],
        ]);

        $models = $handler->where([['type' => 'AND', 'clauses' => [['column' => 'name', 'operator' => '!=', 'value' => '']]]]);

        $this->assertCount(2, $models);
        $this->assertSame('first', $models[0]->get('name'));
        $this->assertSame('second', $models[1]->get('name'));
        $this->assertSame(2, $this->queryStrategy->queryCount, 'A cache outage degraded a batched list read into per-row queries.');
    }

    public function testBusinessKeyUpdateResendingOwnUniqueValuesIsNotADuplicate(): void
    {
        // Self-matches are filtered by the pre-read model's identity: a
        // business-key caller re-sending the record's own unique values must
        // not trip DuplicateEntryException just because their lookup key
        // never equals a model identity.
        $handler = $this->makeHandler(['id'], 'test_api_keys', true, null, null, null, [['keyHash']]);

        // Pre-read resolves the record by business key…
        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        // …and the duplicate scan finds the same record (identity row, then
        // its hydration is served from the cache warmed by the pre-read).
        $this->queryStrategy->queueQueryResult([['id' => '7']]);

        $handler->updateCompound(['keyHash' => 'abc'], ['keyHash' => 'abc', 'status' => 'revoked']);

        $this->assertSame([['keyHash' => 'abc', 'id' => '7'], ['keyHash' => 'abc', 'status' => 'revoked']], $this->queryStrategy->updates[0]);
    }

    /**
     * @return array<string, array{0: array, 1: string, 2: string}>
     */
    public function readOutageLookups(): array
    {
        return [
            'business-key lookup (alias + token reads)' => [['keyHash' => 'abc'], 'id', '7'],
            'canonical lookup (read-through probe)' => [['id' => '7'], 'status', 'active'],
        ];
    }

    /**
     * Probe/read failures must degrade to database loads — never break the
     * lookup, whatever its shape.
     *
     * @dataProvider readOutageLookups
     */
    public function testReadsSurviveACacheThatFailsOnReads(array $lookup, string $field, string $expected): void
    {
        $flaky = $this->useFlakyCache();
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with($this->logicalOr(
                $this->stringContains('read failed'),
                $this->stringContains('store failed'),
                $this->stringContains('Could not cache'),
                $this->stringContains('Could not persist')
            ));

        $handler = $this->makeHandler(['id'], 'test_records', true, $logger);

        $flaky->failReads = true;

        $this->queryStrategy->queueQueryResult([['id' => '7', 'keyHash' => 'abc', 'status' => 'active']]);
        $model = $handler->findByCompound($lookup);

        $this->assertSame($expected, $model->get($field));
    }

    public function testEstimatedCountSurvivesACacheThatFailsOnReads(): void
    {
        $flaky = $this->useFlakyCache();

        $handler = $this->makeHandler(['id'], 'test_records', true);

        $flaky->failReads = true;
        $this->queryStrategy->estimatedCountValue = 9;

        $this->assertSame(9, $handler->getEstimatedCount());
    }

    public function testGenerationReadBlipDoesNotClobberAHealthyToken(): void
    {
        // A read FAILURE mints an ephemeral token without persisting: when
        // reads recover, the original token (and the entries keyed under it)
        // must still be live.
        $flaky = $this->useFlakyCache();

        $handler = $this->makeHandler(['id'], 'test_records', true);

        // Healthy read caches the row under the current token.
        $this->queryStrategy->queueQueryResult([['id' => '7', 'status' => 'active']]);
        $handler->findByCompound(['id' => '7']);
        $storeBefore = $this->cacheStrategy->store;

        // During the blip, the read degrades to the database…
        $flaky->failReads = true;
        $this->queryStrategy->queueQueryResult([['id' => '7', 'status' => 'active']]);
        $handler->findByCompound(['id' => '7']);

        // …and after recovery the original entries are untouched and served.
        $flaky->failReads = false;
        $flaky->failWrites = false;

        foreach ($storeBefore as $key => $value) {
            $this->assertArrayHasKey($key, $this->cacheStrategy->store, 'A read blip clobbered a healthy cache entry.');
        }

        $served = $handler->findByCompound(['id' => '7']);
        $this->assertSame('active', $served->get('status'));
    }

    public function testDuplicateOnADifferentRowSharingTheModelIdentityIsStillCaught(): void
    {
        // Model identity (id only) is a SUBSET of the table identity
        // (orgId + id): a duplicate on org 2's row must not hide behind
        // sharing org 1's model identity.
        $handler = $this->makeHandler(['orgId', 'id'], 'test_records', true, null, null, null, [['keyHash']]);

        // Pre-read: org 1's row, resolved canonically.
        $this->queryStrategy->queueQueryResult([['orgId' => '1', 'id' => '42', 'keyHash' => 'abc']]);
        // Duplicate scan finds org 2's row carrying the same unique value —
        // and the same MODEL identity (id 42).
        $this->queryStrategy->queueQueryResult([['orgId' => '2', 'id' => '42']]);
        $this->queryStrategy->queueQueryResult([['orgId' => '2', 'id' => '42', 'keyHash' => 'abc']]);

        $this->expectException(DuplicateEntryException::class);

        $handler->updateCompound(['orgId' => '1', 'id' => '42'], ['keyHash' => 'abc']);
    }

    public function testConcurrentlyDeletedRowDoesNotCollapseTheListResult(): void
    {
        // A row deleted between the id query and hydration must be skipped —
        // not allowed to throw RecordNotFoundException into where()'s catch,
        // which would discard rows that still exist.
        $handler = $this->makeHandler(['id'], 'test_records', true);

        // findIds sees two rows; the batch SELECT only finds one (row 2
        // vanished); the per-id fallback for row 2 also finds nothing.
        $this->queryStrategy->queueQueryResult([['id' => '1'], ['id' => '2']]);
        $this->queryStrategy->queueQueryResult([['id' => '1', 'name' => 'survivor']]);
        $this->queryStrategy->queueQueryResult([]);

        $models = $handler->where([['type' => 'AND', 'clauses' => [['column' => 'name', 'operator' => '!=', 'value' => '']]]]);

        $this->assertCount(1, $models, 'A concurrently deleted row collapsed the whole result.');
        $this->assertSame('survivor', $models[0]->get('name'));
    }

    public function testMidLoopSqlFailureStillInvalidatesAndAnnouncesCompletedDeletes(): void
    {
        // Row 1 deletes and must still bump the generation and broadcast,
        // even though row 2's SQL delete throws.
        $events = new RecordingEventStrategy();
        $handler = $this->makeHandler(['id'], 'test_records', true, null, $events);

        // Prime a token + a cached row so invalidation is observable.
        $this->queryStrategy->queueQueryResult([['id' => '1', 'status' => 'doomed']]);
        $handler->findByCompound(['id' => '1']);

        $this->queryStrategy->queueQueryResult([['id' => '1'], ['id' => '2']]);
        $this->queryStrategy->throwOnDeleteCall = 2;

        try {
            $handler->deleteWhere([['column' => 'status', 'operator' => '=', 'value' => 'doomed']]);
            $this->fail('Expected the mid-loop SQL failure to propagate.');
        } catch (\LogicException $e) {
            $this->assertSame([['id' => '1']], $this->queryStrategy->deletes, 'Row 1 was not deleted before the failure.');
            $this->assertCount(1, $events->ofType(RecordDeleted::class), 'A completed delete was not announced after a mid-loop failure.');
            $this->assertFalse(
                $this->cacheableService->exists($this->prober->exposeRowContext(['id' => '1'])),
                'The completed delete\'s row entry survived the failure.'
            );
        }
    }

    public function testThrowingListenerDoesNotSuppressRemainingDeleteBroadcasts(): void
    {
        // The FIRST RecordDeleted listener throws; the second row's event
        // must still fire (and the failure is logged, not propagated).
        $events = new ThrowingOnceEventStrategy();
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->atLeastOnce())
            ->method('error')
            ->with($this->stringContains('listener failed'));

        $handler = $this->makeHandler(['id'], 'test_records', true, $logger, $events);

        $this->queryStrategy->queueQueryResult([['id' => '1'], ['id' => '2']]);

        $handler->deleteWhere([['column' => 'status', 'operator' => '=', 'value' => 'doomed']]);

        $this->assertCount(2, $events->ofType(RecordDeleted::class), 'A throwing listener suppressed a sibling RecordDeleted.');
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
}
