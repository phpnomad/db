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
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\ConsistentReadQueryStrategy;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\HasSingleIntIdentity;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

class WithDatastoreHandlerMethodsTest extends TestCase
{
    public function testCreateUsesConsistentReadStrategyForPostInsertHydration(): void
    {
        $queryStrategy = new ConsistentQueryStrategyStub();

        $loggerStrategy = $this->createMock(LoggerStrategy::class);

        $eventStrategy = $this->createMock(EventStrategy::class);
        $eventStrategy->expects($this->once())
            ->method('broadcast');

        $createdModel = new TestModel(123);

        $cacheableService = $this->createMock(CacheableService::class);
        $cacheableService->expects($this->never())
            ->method('exists');
        $cacheableService->expects($this->once())
            ->method('set')
            ->with(['identities' => ['id' => 123], 'type' => TestModel::class], $createdModel);
        $cacheableService->expects($this->once())
            ->method('getWithCache')
            ->willReturn($createdModel);

        $table = $this->createMock(Table::class);
        $table->method('getFieldsForIdentity')->willReturn(['id']);

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->method('getUniqueColumns')->willReturn([]);

        $modelAdapter = $this->createMock(ModelAdapter::class);
        $modelAdapter->expects($this->once())
            ->method('toModel')
            ->with(['id' => 123, 'name' => 'Example'])
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
        $this->assertSame(0, $queryStrategy->queryCalls);
        $this->assertSame(1, $queryStrategy->consistentQueryCalls);
    }

    public function testCreateRethrowsDatastoreErrorsFromPostInsertReread(): void
    {
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects($this->once())
            ->method('insert')
            ->willReturn(['id' => 123]);
        $queryStrategy->expects($this->once())
            ->method('query')
            ->willThrowException(new DatastoreErrorException('Replica read failed'));

        $loggerStrategy = $this->createMock(LoggerStrategy::class);
        $loggerStrategy->expects($this->never())
            ->method('logException');

        $eventStrategy = $this->createMock(EventStrategy::class);
        $eventStrategy->expects($this->never())
            ->method('broadcast');

        $cacheableService = $this->createMock(CacheableService::class);
        $cacheableService->expects($this->never())
            ->method('exists');

        $table = $this->createMock(Table::class);
        $table->method('getFieldsForIdentity')->willReturn(['id']);

        $tableSchemaService = $this->createMock(TableSchemaService::class);
        $tableSchemaService->method('getUniqueColumns')->willReturn([]);

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

        $this->expectException(DatastoreErrorException::class);
        $this->expectExceptionMessage('Replica read failed');

        $handler->create(['name' => 'Example']);
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

class ConsistentQueryStrategyStub implements QueryStrategy, ConsistentReadQueryStrategy
{
    public int $queryCalls = 0;
    public int $consistentQueryCalls = 0;

    public function query(QueryBuilder $builder): array
    {
        $this->queryCalls++;

        throw new RecordNotFoundException('Stale reader did not return the inserted row.');
    }

    public function queryConsistently(QueryBuilder $builder): array
    {
        $this->consistentQueryCalls++;

        return [['id' => 123, 'name' => 'Example']];
    }

    public function insert(Table $table, array $data): array
    {
        return ['id' => 123];
    }

    public function delete(Table $table, array $ids): void
    {
    }

    public function update(Table $table, array $ids, array $data): void
    {
    }

    public function estimatedCount(Table $table): int
    {
        return 0;
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
