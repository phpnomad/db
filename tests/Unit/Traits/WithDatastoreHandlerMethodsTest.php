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

use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\ClauseBuilder;
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
        $cacheableService->expects($this->once())
            ->method('exists')
            ->willReturn(false);

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

    public function testCacheContextIsTypeStableAcrossIntAndStringIdentities(): void
    {
        // MySQL returns identity columns as strings, while hydrated models hold
        // them as ints. Both forms of the same record must share one cache key.
        $handler = $this->makeHandler(
            $this->createMock(QueryStrategy::class),
            $this->createMock(CacheableService::class),
            $this->createMock(ModelAdapter::class)
        );

        $this->assertSame(
            $handler->exposeCacheContext(['id' => 123]),
            $handler->exposeCacheContext(['id' => '123'])
        );
    }

    public function testWhereLoadsAPageWithoutAPerRowReadBack(): void
    {
        // where() fetches identities (as strings, the MySQL shape), batch-loads
        // the rows, caches them by the hydrated model's int identity, and then
        // reads each one back from that cache. When the two identity forms hashed
        // to different keys, every row missed the cache and cost its own SELECT,
        // so a 200-row page issued 202 queries instead of 2.
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects($this->exactly(2))
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                [['id' => '1'], ['id' => '2'], ['id' => '3']],
                [['id' => '1'], ['id' => '2'], ['id' => '3']]
            );

        $modelAdapter = $this->createMock(ModelAdapter::class);
        $modelAdapter->method('toModel')
            ->willReturnCallback(fn(array $row) => new TestModel((int) $row['id']));

        $cacheableService = new CacheableService(
            $this->createMock(EventStrategy::class),
            new ArrayCacheStrategy(),
            new SerializedContextCachePolicy()
        );

        $handler = $this->makeHandler($queryStrategy, $cacheableService, $modelAdapter);

        $models = $handler->where([], 3);

        $this->assertSame([1, 2, 3], array_map(fn(TestModel $model) => $model->getId(), $models));
    }

    private function makeHandler(QueryStrategy $queryStrategy, CacheableService $cacheableService, ModelAdapter $modelAdapter): DummyDatastoreHandler
    {
        $table = $this->createMock(Table::class);
        $table->method('getFieldsForIdentity')->willReturn(['id']);
        $table->method('getName')->willReturn('test_records');

        $serviceProvider = new DatabaseServiceProvider(
            $this->createMock(LoggerStrategy::class),
            $queryStrategy,
            new DummyQueryBuilder(),
            new DummyClauseBuilder(),
            $cacheableService,
            $this->createMock(EventStrategy::class)
        );

        return new DummyDatastoreHandler(
            $serviceProvider,
            $table,
            $this->createMock(TableSchemaService::class),
            TestModel::class,
            $modelAdapter
        );
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

class ArrayCacheStrategy implements CacheStrategy
{
    private array $items = [];

    public function get(string $key)
    {
        return $this->items[$key];
    }

    public function set(string $key, $value, ?int $ttl): void
    {
        $this->items[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }
}

class SerializedContextCachePolicy implements CachePolicy
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
        return null;
    }

    public function shouldInvalidate(string $operation, array $context = []): bool
    {
        return false;
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
