<?php

namespace PHPNomad\Events\Interfaces {
    if (!interface_exists(Event::class)) {
        interface Event
        {
        }
    }

    if (!interface_exists(EventStrategy::class)) {
        interface EventStrategy
        {
            public function broadcast(Event $event): void;
        }
    }
}

namespace PHPNomad\Database\Tests\Unit\Traits {

use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;
use PHPNomad\Datastore\Events\RecordCreated;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
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
        $cacheableService->expects($this->once())
            ->method('set')
            ->with(['identities' => ['id' => '123'], 'type' => TestModel::class], $createdModel);

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
            new DummyQueryBuilder(),
            new DummyClauseBuilder(),
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
        $cacheableService->expects($this->once())->method('set');

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
            new DummyQueryBuilder(),
            new DummyClauseBuilder(),
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

        $handler->create(['name' => 'Example']);
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

        $modelAdapter = $this->createMock(ModelAdapter::class);
        $modelAdapter->method('toModel')->willReturn(new TestModel(7));

        $serviceProvider = new DatabaseServiceProvider(
            $loggerStrategy,
            $queryStrategy,
            new DummyQueryBuilder(),
            new DummyClauseBuilder(),
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

        $handler->create(['createdAt' => 'caller-provided']);
    }

    public function testCacheContextIsTypeStableAcrossIntAndStringIdentities(): void
    {
        // Regression: MySQL returns identity columns as strings, but hydrated
        // models hold them as ints. Without normalization, the same record
        // produces two distinct cache entries and updateCompound() only
        // invalidates one of them — leaving the other to serve stale reads.
        $loggerStrategy = $this->createMock(LoggerStrategy::class);
        $eventStrategy = $this->createMock(EventStrategy::class);
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $cacheableService = $this->createMock(CacheableService::class);
        $table = $this->createMock(Table::class);
        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $modelAdapter = $this->createMock(ModelAdapter::class);

        $serviceProvider = new DatabaseServiceProvider(
            $loggerStrategy,
            $queryStrategy,
            new DummyQueryBuilder(),
            new DummyClauseBuilder(),
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

        $intContext = $handler->exposeCacheContext(['id' => 123]);
        $stringContext = $handler->exposeCacheContext(['id' => '123']);

        $this->assertSame($intContext, $stringContext);
    }

    public function testUpdateCompoundInvalidatesCacheRegardlessOfIdentityType(): void
    {
        // Drives the actual stale-read scenario end-to-end: cacheItems writes
        // with int identity (from the hydrated model), updateCompound is called
        // with the string identity that came back from queryStrategy->query()
        // — both must hit the same cache key for the invalidation to land.
        $loggerStrategy = $this->createMock(LoggerStrategy::class);
        $eventStrategy = $this->createMock(EventStrategy::class);
        $queryStrategy = $this->createMock(QueryStrategy::class);

        $deletedKeys = [];
        $cacheableService = $this->createMock(CacheableService::class);
        $cacheableService->method('getWithCache')
            ->willReturnCallback(fn(string $operation, array $context, callable $callback) => $callback());
        $cacheableService->expects($this->once())
            ->method('delete')
            ->willReturnCallback(function (array $context) use (&$deletedKeys) {
                $deletedKeys[] = $context;
            });

        // findFromCompound() runs first inside updateCompound() to load the
        // existing record; return one row so the update path proceeds.
        $queryStrategy->method('query')->willReturn([['id' => 42, 'name' => 'old']]);

        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('test_records');
        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->method('getUniqueColumns')->willReturn([]);
        $modelAdapter = $this->createMock(ModelAdapter::class);
        $modelAdapter->method('toModel')->willReturn(new TestModel(42));

        $serviceProvider = new DatabaseServiceProvider(
            $loggerStrategy,
            $queryStrategy,
            new DummyQueryBuilder(),
            new DummyClauseBuilder(),
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

        // The deleted cache context must match what cacheItems wrote earlier,
        // which used the int identity from the hydrated model.
        $expected = $handler->exposeCacheContext(['id' => 42]);
        $this->assertCount(1, $deletedKeys);
        $this->assertSame($expected, $deletedKeys[0]);
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

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $modelAdapter = $this->createMock(ModelAdapter::class);

        $serviceProvider = new DatabaseServiceProvider(
            $loggerStrategy,
            $queryStrategy,
            new DummyQueryBuilder(),
            new DummyClauseBuilder(),
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

        $this->expectException(RecordNotFoundException::class);
        $this->expectExceptionMessage('Record not found in table "test_records"');
        $this->expectExceptionMessage('"id":123');

        $handler->findByIdentity(['id' => 123]);
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

    public function exposeCacheContext(array $ids): array
    {
        return $this->getCacheContextForItem($ids);
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

class DummyQueryBuilder implements QueryBuilder
{
    public function useTable(Table $table)
    {
        return $this;
    }

    public function select(string $field, string ...$fields)
    {
        return $this;
    }

    public function from(Table $table)
    {
        return $this;
    }

    public function where(?ClauseBuilder $clauseBuilder)
    {
        return $this;
    }

    public function leftJoin(Table $table, string $column, string $onColumn)
    {
        return $this;
    }

    public function rightJoin(Table $table, string $column, string $onColumn)
    {
        return $this;
    }

    public function groupBy(string $column, string ...$columns)
    {
        return $this;
    }

    public function sum(string $fieldToSum, ?string $alias = null)
    {
        return $this;
    }

    public function count(string $fieldToCount, ?string $alias = null)
    {
        return $this;
    }

    public function limit(int $limit)
    {
        return $this;
    }

    public function offset(int $offset)
    {
        return $this;
    }

    public function orderBy(string $field, string $order)
    {
        return $this;
    }

    public function build(): string
    {
        return 'SELECT * FROM test_table';
    }

    public function reset()
    {
        return $this;
    }

    public function resetClauses(string $clause, string ...$clauses)
    {
        return $this;
    }
}

class DummyClauseBuilder implements ClauseBuilder
{
    public function useTable(Table $table)
    {
        return $this;
    }

    public function where($field, string $operator, ...$values)
    {
        return $this;
    }

    public function andWhere($field, string $operator, ...$values)
    {
        return $this;
    }

    public function orWhere($field, string $operator, ...$values)
    {
        return $this;
    }

    public function group(string $logic, ClauseBuilder ...$clauses)
    {
        return $this;
    }

    public function andGroup(string $logic, ClauseBuilder ...$clauses)
    {
        return $this;
    }

    public function orGroup(string $logic, ClauseBuilder ...$clauses)
    {
        return $this;
    }

    public function build(): string
    {
        return 'id = 123';
    }

    public function reset()
    {
        return $this;
    }
}
}
