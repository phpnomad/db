<?php

namespace PHPNomad\Database\Tests\Unit\Traits;

use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Adapters\RowCacheContextAdapter;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\Doubles\NoopClauseBuilder;
use PHPNomad\Database\Tests\Doubles\NoopQueryBuilder;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;
use PHPNomad\Datastore\Events\RecordCreated;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\HasSingleIntIdentity;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

class WithDatastoreHandlerMethodsTest extends TestCase
{
    public function testCreateHydratesFromAttributesWithoutPostInsertRead(): void
    {
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects($this->once())
            ->method('insert')
            ->with($this->anything(), ['name' => 'Example'])
            ->willReturn(['id' => 123]);
        // Critical: no post-insert read. This is the operation that races
        // read-replicas behind write/read-split routers.
        $queryStrategy->expects($this->never())->method('query');

        $loggerStrategy = $this->createMock(LoggerStrategy::class);

        $createdModel = new TestModel(123);

        $eventStrategy = $this->createMock(EventStrategy::class);
        $eventStrategy->expects($this->once())
            ->method('broadcast')
            ->with($this->isInstanceOf(RecordCreated::class));

        $cacheableService = $this->createMock(CacheableService::class);
        $cacheableService->expects($this->never())->method('exists');
        // No pre-warm: create() never caches the attribute-hydrated model.
        // Its only cache write path here (generations disabled) is the
        // set-level context delete.
        $cacheableService->expects($this->never())->method('set');
        $cacheableService->expects($this->once())
            ->method('delete')
            ->with(['type' => TestModel::class]);

        $table = $this->createMock(Table::class);
        $table->method('getFieldsForIdentity')->willReturn(['id']);
        $table->method('getColumns')->willReturn([]);

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->method('getUniqueColumns')->willReturn([]);

        $modelAdapter = $this->createMock(ModelAdapter::class);
        $modelAdapter->expects($this->once())
            ->method('toModel')
            ->with(['name' => 'Example', 'id' => 123])
            ->willReturn($createdModel);

        $serviceProvider = new DatabaseServiceProvider(
            $loggerStrategy,
            $queryStrategy,
            new NoopQueryBuilder(),
            new NoopClauseBuilder(),
            $cacheableService,
            $eventStrategy
        );

        $handler = new DummyDatastoreHandler(
            $serviceProvider,
            $table,
            $tableSchemaService,
            TestModel::class,
            $modelAdapter
        );

        $result = $handler->create(['name' => 'Example']);

        $this->assertSame($createdModel, $result);
    }

    public function testCreateAppliesPhpDefaultsForMissingColumns(): void
    {
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects($this->once())
            ->method('insert')
            ->with($this->anything(), [
                'name' => 'Example',
                'createdAt' => 'php-default-value',
            ])
            ->willReturn(['id' => 123]);
        $queryStrategy->expects($this->never())->method('query');

        $loggerStrategy = $this->createMock(LoggerStrategy::class);
        $eventStrategy = $this->createMock(EventStrategy::class);

        $createdModel = new TestModel(123);

        $cacheableService = $this->createMock(CacheableService::class);
        $cacheableService->expects($this->never())->method('set');

        $nameColumn = new Column('name', 'VARCHAR', [255]);
        $createdAtColumn = (new Column('createdAt', 'TIMESTAMP'))
            ->withPhpDefault(static fn () => 'php-default-value');

        $table = $this->createMock(Table::class);
        $table->method('getFieldsForIdentity')->willReturn(['id']);
        $table->method('getColumns')->willReturn([$nameColumn, $createdAtColumn]);

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->method('getUniqueColumns')->willReturn([]);

        $modelAdapter = $this->createMock(ModelAdapter::class);
        $modelAdapter->expects($this->once())
            ->method('toModel')
            ->with([
                'name' => 'Example',
                'createdAt' => 'php-default-value',
                'id' => 123,
            ])
            ->willReturn($createdModel);

        $serviceProvider = new DatabaseServiceProvider(
            $loggerStrategy,
            $queryStrategy,
            new NoopQueryBuilder(),
            new NoopClauseBuilder(),
            $cacheableService,
            $eventStrategy
        );

        $handler = new DummyDatastoreHandler(
            $serviceProvider,
            $table,
            $tableSchemaService,
            TestModel::class,
            $modelAdapter
        );

        $this->assertSame($createdModel, $handler->create(['name' => 'Example']));
    }

    public function testCreateRespectsCallerProvidedValuesOverPhpDefaults(): void
    {
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects($this->once())
            ->method('insert')
            ->with($this->anything(), [
                'createdAt' => 'caller-provided', // not php-default-value
            ])
            ->willReturn(['id' => 7]);

        $loggerStrategy = $this->createMock(LoggerStrategy::class);
        $eventStrategy = $this->createMock(EventStrategy::class);
        $cacheableService = $this->createMock(CacheableService::class);

        $createdAtColumn = (new Column('createdAt', 'TIMESTAMP'))
            ->withPhpDefault(static fn () => 'php-default-value');

        $table = $this->createMock(Table::class);
        $table->method('getFieldsForIdentity')->willReturn(['id']);
        $table->method('getColumns')->willReturn([$createdAtColumn]);

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->method('getUniqueColumns')->willReturn([]);

        $createdModel = new TestModel(7);
        $modelAdapter = $this->createMock(ModelAdapter::class);
        $modelAdapter->method('toModel')->willReturn($createdModel);

        $serviceProvider = new DatabaseServiceProvider(
            $loggerStrategy,
            $queryStrategy,
            new NoopQueryBuilder(),
            new NoopClauseBuilder(),
            $cacheableService,
            $eventStrategy
        );

        $handler = new DummyDatastoreHandler(
            $serviceProvider,
            $table,
            $tableSchemaService,
            TestModel::class,
            $modelAdapter
        );

        $this->assertSame($createdModel, $handler->create(['createdAt' => 'caller-provided']));
    }

    public function testUpdateCompoundInvalidatesCacheRegardlessOfIdentityType(): void
    {
        // Drives the actual stale-read scenario end-to-end: the read path
        // caches rows with int identity values, updateCompound is called
        // with the string identity that came back from queryStrategy->query()
        // — both must hit the same cache key for the invalidation to land.
        $loggerStrategy = $this->createMock(LoggerStrategy::class);
        $eventStrategy = $this->createMock(EventStrategy::class);
        $queryStrategy = $this->createMock(QueryStrategy::class);

        $deletedKeys = [];
        $cacheableService = $this->createMock(CacheableService::class);
        $cacheableService->method('getWithCache')
            ->willReturnCallback(fn(string $operation, array $context, callable $callback) => $callback());
        // Two deletes: the canonical row entry, then the set-level context
        // (this handler opts out of generations, so estimatedCount has no
        // bump to orphan it and is deleted precisely).
        $cacheableService->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function (array $context) use (&$deletedKeys) {
                $deletedKeys[] = $context;
            });

        // findFromCompound() runs first inside updateCompound() to load the
        // existing record; return one row so the update path proceeds.
        $queryStrategy->method('query')->willReturn([['id' => 42, 'name' => 'old']]);

        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('test_records');
        $table->method('getFieldsForIdentity')->willReturn(['id']);
        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->method('getUniqueColumns')->willReturn([]);
        $modelAdapter = $this->createMock(ModelAdapter::class);
        $modelAdapter->method('toModel')->willReturn(new TestModel(42));

        $serviceProvider = new DatabaseServiceProvider(
            $loggerStrategy,
            $queryStrategy,
            new NoopQueryBuilder(),
            new NoopClauseBuilder(),
            $cacheableService,
            $eventStrategy
        );

        $handler = new DummyDatastoreHandler(
            $serviceProvider,
            $table,
            $tableSchemaService,
            TestModel::class,
            $modelAdapter
        );

        // String identity (the shape MySQL returns).
        $handler->updateCompound(['id' => '42'], ['name' => 'new']);

        // The deleted cache context must match what the read path wrote,
        // which used the int identity from the hydrated row.
        $contextAdapter = new RowCacheContextAdapter($table, TestModel::class, $modelAdapter, false);
        $expected = $contextAdapter->toRowContext(['id' => 42], null);
        $this->assertCount(2, $deletedKeys);
        $this->assertSame($expected, $deletedKeys[0]);
        $this->assertSame(['type' => TestModel::class], $deletedKeys[1]);
    }

    public function testFindFromCompoundIncludesTableAndIdentityWhenRecordIsMissing(): void
    {
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects($this->once())
            ->method('query')
            ->willReturn([]);

        $loggerStrategy = $this->createMock(LoggerStrategy::class);
        $eventStrategy = $this->createMock(EventStrategy::class);

        $cacheableService = $this->createMock(CacheableService::class);
        $cacheableService->expects($this->once())
            ->method('getWithCache')
            ->willReturnCallback(fn(string $operation, array $context, callable $callback) => $callback());

        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('test_records');
        $table->method('getFieldsForIdentity')->willReturn(['id']);

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $modelAdapter = $this->createMock(ModelAdapter::class);

        $serviceProvider = new DatabaseServiceProvider(
            $loggerStrategy,
            $queryStrategy,
            new NoopQueryBuilder(),
            new NoopClauseBuilder(),
            $cacheableService,
            $eventStrategy
        );

        $handler = new DummyDatastoreHandler(
            $serviceProvider,
            $table,
            $tableSchemaService,
            TestModel::class,
            $modelAdapter
        );

        try {
            $handler->findByIdentity(['id' => 123]);
            $this->fail('Expected RecordNotFoundException was not thrown.');
        } catch (RecordNotFoundException $e) {
            // expectExceptionMessage() keeps only its LAST invocation, so
            // both fragments are pinned explicitly.
            $this->assertStringContainsString('Record not found in table "test_records"', $e->getMessage());
            $this->assertStringContainsString('"id":123', $e->getMessage());
        }
    }
}

class DummyDatastoreHandler
{
    use WithDatastoreHandlerMethods;

    public function __construct(
        DatabaseServiceProvider $serviceProvider,
        Table $table,
        TableSchemaService $tableSchemaService,
        string $model,
        ModelAdapter $modelAdapter
    ) {
        $this->serviceProvider = $serviceProvider;
        $this->table = $table;
        $this->tableSchemaService = $tableSchemaService;
        $this->model = $model;
        $this->modelAdapter = $modelAdapter;
    }

    public function findByIdentity(array $ids)
    {
        return $this->findFromCompound($ids);
    }

    /**
     * Legacy tests assert precise per-key cache interactions against a mocked
     * CacheableService; generations would add token get/set chatter that
     * belongs to the dedicated generation tests.
     */
    protected function shouldUseTableGenerations(): bool
    {
        return false;
    }
}

class TestModel implements DataModel, HasSingleIntIdentity
{
    public function __construct(private int $id = 1)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getIdentity(): array
    {
        return ['id' => $this->id];
    }
}
