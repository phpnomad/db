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
    public function testCreateHydratesCreatedModelFromInsertedAttributesWithoutReadback(): void
    {
        $createdModel = new TestModel(123);

        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects($this->once())
            ->method('insert')
            ->with($this->anything(), ['name' => 'Example'])
            ->willReturn(['id' => 123]);
        $queryStrategy->expects($this->never())
            ->method('query');

        $loggerStrategy = $this->createMock(LoggerStrategy::class);
        $loggerStrategy->expects($this->never())
            ->method('logException');

        $eventStrategy = $this->createMock(EventStrategy::class);
        $eventStrategy->expects($this->once())
            ->method('broadcast')
            ->with($this->callback(
                fn($event) => $event instanceof RecordCreated && $event->getRecord() === $createdModel
            ));

        $cacheableService = $this->createMock(CacheableService::class);
        $cacheableService->expects($this->never())
            ->method('exists');
        $cacheableService->expects($this->never())
            ->method('set');
        $cacheableService->expects($this->never())
            ->method('getWithCache');

        $table = $this->createMock(Table::class);
        $table->method('getFieldsForIdentity')->willReturn(['id']);

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

        $this->assertSame($createdModel, $handler->create(['name' => 'Example']));
    }

    public function testCreateRemovesProvidedAutoIncrementIdentityBeforeInsertAndUsesResolvedIdentity(): void
    {
        $createdModel = new TestModel(123);

        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects($this->once())
            ->method('insert')
            ->with($this->anything(), ['name' => 'Example'])
            ->willReturn(['id' => 123]);
        $queryStrategy->expects($this->never())
            ->method('query');

        $loggerStrategy = $this->createMock(LoggerStrategy::class);
        $loggerStrategy->expects($this->never())
            ->method('logException');

        $eventStrategy = $this->createMock(EventStrategy::class);
        $eventStrategy->expects($this->once())
            ->method('broadcast')
            ->with($this->callback(
                fn($event) => $event instanceof RecordCreated && $event->getRecord() === $createdModel
            ));

        $cacheableService = $this->createMock(CacheableService::class);
        $cacheableService->expects($this->never())
            ->method('exists');
        $cacheableService->expects($this->never())
            ->method('set');
        $cacheableService->expects($this->never())
            ->method('getWithCache');

        $table = $this->createMock(Table::class);
        $table->method('getFieldsForIdentity')->willReturn(['id']);

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

        $this->assertSame($createdModel, $handler->create(['id' => 999, 'name' => 'Example']));
    }

    public function testCreateRethrowsDatastoreErrorsFromInsert(): void
    {
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects($this->once())
            ->method('insert')
            ->willThrowException(new DatastoreErrorException('Insert failed'));
        $queryStrategy->expects($this->never())
            ->method('query');

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
        $this->expectExceptionMessage('Insert failed');

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
