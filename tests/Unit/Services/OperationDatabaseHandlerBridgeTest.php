<?php

namespace PHPNomad\Database\Tests\Unit\Services;

use Closure;
use InvalidArgumentException;
use PHPNomad\Cache\Interfaces\CachePolicy;
use PHPNomad\Cache\Interfaces\CacheStrategy;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\DatabaseHandler;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table as TableContract;
use PHPNomad\Database\Models\CoordinatedOperationResult;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Services\CloningOperationDatabaseProviderFactory;
use PHPNomad\Database\Services\OperationCacheableService;
use PHPNomad\Database\Services\OperationDatabaseHandlerBridge;
use PHPNomad\Database\Services\OperationEventStrategy;
use PHPNomad\Events\Interfaces\Event;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPNomad\Database\Factories\DatabaseDatastoreHandler;
use PHPNomad\Database\Tests\TestCase;

class OperationDatabaseHandlerBridgeTest extends TestCase
{
    public function testSuccessfulOperationsUseIndependentProvidersAndPublishAfterCommit(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $sharedCache = $this->createMock(CacheStrategy::class);
        $sharedCache->expects($this->exactly(2))->method('delete');
        $sharedCache->expects($this->never())->method('exists');
        $sharedCacheable = $this->cacheable($sharedEvents, $sharedCache);
        $provider = $this->provider($sharedEvents, $sharedCacheable);
        $handler = $this->handler($provider, 'records');
        $operationQuery = $this->createMock(QueryStrategy::class);
        $coordinator = $this->coordinator($operationQuery);
        $provider->queryStrategy = $coordinator;
        $event = $this->createMock(Event::class);
        $sharedEvents->expects($this->once())->method('broadcast')->with($event);
        $factoryCalled = false;

        $result = (new OperationDatabaseHandlerBridge(new BridgeProviderFactory(
            function ($databaseHandler, QueryStrategy $query, $cache, $events) use (&$factoryCalled, $provider) {
                $factoryCalled = true;

                return $provider->forOperation($query, clone $provider->queryBuilder, clone $provider->clauseBuilder, $cache, $events);
            }
        )))->coordinate(
            $coordinator,
            $handler->getDatabaseTable(),
            ['id' => 1],
            [$handler->getDatabaseTable()],
            ['record' => $handler],
            function (array $handlers, QueryStrategy $query) use ($handler, $operationQuery, $event): string {
                $local = $handlers['record'];
                self::assertInstanceOf(BridgeTestHandler::class, $local);
                self::assertSame('preserve-me', $local->marker);
                self::assertSame($operationQuery, $local->getDatabaseServiceProvider()->queryStrategy);
                self::assertNotSame($handler, $local);
                self::assertNotSame(
                    $handler->getDatabaseServiceProvider()->queryBuilder,
                    $local->getDatabaseServiceProvider()->queryBuilder
                );
                $local->getDatabaseServiceProvider()->cacheableService->set(['id' => 9], 'fresh');
                self::assertSame(
                    'fresh',
                    $local->getDatabaseServiceProvider()->cacheableService->getWithCache(
                        'read',
                        ['id' => 9],
                        static function (): string {
                            throw new \LogicException('shared cache was consulted');
                        }
                    )
                );
                $local->getDatabaseServiceProvider()->cacheableService->delete(['id' => 1]);
                $local->getDatabaseServiceProvider()->eventStrategy->broadcast($event);

                return 'committed';
            }
        );

        self::assertTrue($factoryCalled);
        self::assertInstanceOf(CoordinatedOperationResult::class, $result);
        self::assertSame('committed', $result->getValue());
        self::assertSame([], $result->getPublicationFailures());
        self::assertSame($coordinator, $provider->queryStrategy);
        self::assertSame($sharedCacheable, $provider->cacheableService);
        self::assertSame($sharedEvents, $provider->eventStrategy);
    }

    public function testCallbackFailureDiscardsCacheAndEvents(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $sharedEvents->expects($this->never())->method('broadcast');
        $sharedCache = $this->createMock(CacheStrategy::class);
        $sharedCache->expects($this->never())->method('delete');
        $sharedCacheable = $this->cacheable($sharedEvents, $sharedCache);
        $provider = $this->provider($sharedEvents, $sharedCacheable);
        $handler = $this->handler($provider, 'records');
        $operationQuery = $this->createMock(QueryStrategy::class);
        $coordinator = $this->coordinator($operationQuery);
        $provider->queryStrategy = $coordinator;
        $event = $this->createMock(Event::class);

        $this->expectException(\RuntimeException::class);
        $this->bridge()->coordinate(
            $coordinator,
            $handler->getDatabaseTable(),
            ['id' => 1],
            [$handler->getDatabaseTable()],
            [$handler],
            function (array $handlers) use ($event): void {
                $provider = $handlers[0]->getDatabaseServiceProvider();
                $provider->cacheableService->delete(['id' => 1]);
                $provider->eventStrategy->broadcast($event);
                throw new \RuntimeException('rollback');
            }
        );
    }

    public function testHandlerFromAnotherParticipantIsRejectedBeforeCoordination(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $sharedCacheable = $this->cacheable($sharedEvents, $this->createMock(CacheStrategy::class));
        $first = $this->handler($this->provider($sharedEvents, $sharedCacheable), 'first');
        $second = $this->handler($this->provider($sharedEvents, $sharedCacheable), 'second');
        $coordinator = $this->createMock(CoordinatedQueryStrategy::class);
        $coordinator->expects($this->never())->method('coordinate');
        $first->getDatabaseServiceProvider()->queryStrategy = $coordinator;
        $second->getDatabaseServiceProvider()->queryStrategy = $coordinator;

        $this->expectException(InvalidArgumentException::class);
        $this->bridge()->coordinate(
            $coordinator,
            $first->getDatabaseTable(),
            ['id' => 1],
            [$first->getDatabaseTable()],
            [$second],
            static function (): void {}
        );
    }

    public function testCommittedResultRetainsCacheAndEventPublicationFailures(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $sharedEvents->expects($this->once())->method('broadcast')->willThrowException(new \RuntimeException('event publish'));
        $sharedCache = $this->createMock(CacheStrategy::class);
        $sharedCache->expects($this->once())->method('delete')->willThrowException(new \RuntimeException('cache invalidate'));
        $sharedCacheable = $this->cacheable($sharedEvents, $sharedCache);
        $provider = $this->provider($sharedEvents, $sharedCacheable);
        $handler = $this->handler($provider, 'records');
        $operationQuery = $this->createMock(QueryStrategy::class);
        $coordinator = $this->coordinator($operationQuery);
        $provider->queryStrategy = $coordinator;
        $event = $this->createMock(Event::class);

        $result = $this->bridge()->coordinate(
            $coordinator,
            $handler->getDatabaseTable(),
            ['id' => 1],
            [$handler->getDatabaseTable()],
            [$handler],
            function (array $handlers) use ($event): string {
                $provider = $handlers[0]->getDatabaseServiceProvider();
                $provider->cacheableService->delete(['id' => 1]);
                $provider->eventStrategy->broadcast($event);

                return 'committed';
            }
        );

        self::assertSame('committed', $result->getValue());
        self::assertCount(2, $result->getPublicationFailures());
        self::assertContainsOnlyInstancesOf(\RuntimeException::class, $result->getPublicationFailures());
    }

    public function testPublicationLoggingFailureIsRetainedAndLaterPublicationsContinue(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $sharedEvents->expects($this->once())->method('broadcast')->willThrowException(new \RuntimeException('event publish'));
        $sharedCache = $this->createMock(CacheStrategy::class);
        $sharedCache->expects($this->exactly(2))->method('delete')->willReturnCallback(static function (string $key): void {
            throw new \RuntimeException('cache invalidate ' . $key);
        });
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->exactly(3))->method('logException')->willThrowException(new \RuntimeException('logger failed'));
        $sharedCacheable = $this->cacheable($sharedEvents, $sharedCache);
        $provider = $this->provider($sharedEvents, $sharedCacheable, $logger);
        $handler = $this->handler($provider, 'records');
        $operationQuery = $this->createMock(QueryStrategy::class);
        $coordinator = $this->coordinator($operationQuery);
        $provider->queryStrategy = $coordinator;
        $event = $this->createMock(Event::class);

        $result = $this->bridge()->coordinate(
            $coordinator,
            $handler->getDatabaseTable(),
            ['id' => 1],
            [$handler->getDatabaseTable()],
            [$handler],
            function (array $handlers) use ($event): string {
                $provider = $handlers[0]->getDatabaseServiceProvider();
                $provider->cacheableService->set(['id' => 2], 'created');
                $provider->cacheableService->delete(['id' => 1]);
                $provider->eventStrategy->broadcast($event);

                return 'committed';
            }
        );

        self::assertSame('committed', $result->getValue());
        self::assertCount(6, $result->getPublicationFailures());
        self::assertContainsOnlyInstancesOf(\RuntimeException::class, $result->getPublicationFailures());
    }

    public function testHandlerUsingAnotherCoordinatorIsRejectedBeforeCoordination(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $sharedCacheable = $this->cacheable($sharedEvents, $this->createMock(CacheStrategy::class));
        $provider = $this->provider($sharedEvents, $sharedCacheable);
        $handler = $this->handler($provider, 'records');
        $coordinator = $this->createMock(CoordinatedQueryStrategy::class);
        $coordinator->expects($this->never())->method('coordinate');

        $this->expectException(InvalidArgumentException::class);
        $this->bridge()->coordinate(
            $coordinator,
            $handler->getDatabaseTable(),
            ['id' => 1],
            [$handler->getDatabaseTable()],
            [$handler],
            static function (): void {}
        );
    }

    public function testHandlersWithDifferentSharedPoliciesAreRejectedBeforeCoordination(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $firstProvider = $this->provider($sharedEvents, $this->cacheable($sharedEvents, $this->createMock(CacheStrategy::class)));
        $secondProvider = $this->provider($sharedEvents, $this->cacheable($sharedEvents, $this->createMock(CacheStrategy::class)));
        $first = $this->handler($firstProvider, 'first');
        $second = $this->handler($secondProvider, 'second');
        $coordinator = $this->createMock(CoordinatedQueryStrategy::class);
        $firstProvider->queryStrategy = $coordinator;
        $secondProvider->queryStrategy = $coordinator;
        $coordinator->expects($this->never())->method('coordinate');

        $this->expectException(InvalidArgumentException::class);
        $this->bridge()->coordinate(
            $coordinator,
            $first->getDatabaseTable(),
            ['id' => 1],
            [$first->getDatabaseTable(), $second->getDatabaseTable()],
            [$first, $second],
            static function (): void {}
        );
    }

    public function testFactoryCannotEscapeOperationLocalCacheOrEvents(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $sharedCacheable = $this->cacheable($sharedEvents, $this->createMock(CacheStrategy::class));
        $provider = $this->provider($sharedEvents, $sharedCacheable);
        $handler = $this->handler($provider, 'records');
        $operationQuery = $this->createMock(QueryStrategy::class);
        $coordinator = $this->coordinator($operationQuery);
        $provider->queryStrategy = $coordinator;

        $this->expectException(InvalidArgumentException::class);
        (new OperationDatabaseHandlerBridge(new BridgeProviderFactory(
            function (DatabaseHandler $source, QueryStrategy $query) use ($provider): DatabaseServiceProvider {
                return $provider->forOperation($query, clone $provider->queryBuilder, clone $provider->clauseBuilder, $provider->cacheableService, $provider->eventStrategy);
            }
        )))->coordinate(
            $coordinator,
            $handler->getDatabaseTable(),
            ['id' => 1],
            [$handler->getDatabaseTable()],
            [$handler],
            static function (): void {
                self::fail('The operation callback must not run.');
            }
        );
    }

    public function testFactoryCannotReuseMutableBuildersAcrossHandlers(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $sharedCacheable = $this->cacheable($sharedEvents, $this->createMock(CacheStrategy::class));
        $firstProvider = $this->provider($sharedEvents, $sharedCacheable);
        $secondProvider = $this->provider($sharedEvents, $sharedCacheable);
        $secondProvider->loggerStrategy = $firstProvider->loggerStrategy;
        $first = $this->handler($firstProvider, 'first');
        $second = $this->handler($secondProvider, 'second');
        $operationQuery = $this->createMock(QueryStrategy::class);
        $coordinator = $this->coordinator($operationQuery);
        $firstProvider->queryStrategy = $coordinator;
        $secondProvider->queryStrategy = $coordinator;
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $clauseBuilder = $this->createMock(ClauseBuilder::class);

        $this->expectException(InvalidArgumentException::class);
        (new OperationDatabaseHandlerBridge(new BridgeProviderFactory(
            function (DatabaseHandler $source, QueryStrategy $query, OperationCacheableService $cache, OperationEventStrategy $events) use ($queryBuilder, $clauseBuilder): DatabaseServiceProvider {
                return new DatabaseServiceProvider(
                    $source->getDatabaseServiceProvider()->loggerStrategy,
                    $query,
                    $queryBuilder,
                    $clauseBuilder,
                    $cache,
                    $events
                );
            }
        )))->coordinate(
            $coordinator,
            $first->getDatabaseTable(),
            ['id' => 1],
            [$first->getDatabaseTable(), $second->getDatabaseTable()],
            [$first, $second],
            static function (): void {
                self::fail('The operation callback must not run.');
            }
        );
    }

    public function testOperationEventBindingsCannotMutateSharedStrategy(): void
    {
        $sharedEvents = $this->createMock(EventStrategy::class);
        $events = new OperationEventStrategy($sharedEvents);

        $this->expectException(\PHPNomad\Database\Exceptions\UnsupportedCoordinationException::class);
        $events->attach('record.created', static function (): void {});
    }

    private function coordinator(QueryStrategy $operationQuery): CoordinatedQueryStrategy
    {
        $coordinator = $this->createMock(CoordinatedQueryStrategy::class);
        $coordinator->method('coordinate')->willReturnCallback(static function ($table, $identity, $participants, $operation) use ($operationQuery) {
            return $operation($operationQuery);
        });

        return $coordinator;
    }

    private function bridge(): OperationDatabaseHandlerBridge
    {
        return new OperationDatabaseHandlerBridge(new CloningOperationDatabaseProviderFactory());
    }

    private function handler(DatabaseServiceProvider $provider, string $name): DatabaseDatastoreHandler
    {
        $table = $this->createMock(TableContract::class);
        $table->method('getName')->willReturn($name);

        return new BridgeTestHandler(
            $provider,
            $this->createMock(\PHPNomad\Database\Interfaces\DatabaseContextProvider::class),
            $this->createMock(\PHPNomad\Database\Services\TableSchemaService::class),
            $table
        );
    }

    private function provider(EventStrategy $events, CacheableService $cacheable, ?LoggerStrategy $logger = null): DatabaseServiceProvider
    {
        return new DatabaseServiceProvider(
            $logger ?: $this->createMock(LoggerStrategy::class),
            $this->createMock(QueryStrategy::class),
            $this->createMock(QueryBuilder::class),
            $this->createMock(ClauseBuilder::class),
            $cacheable,
            $events
        );
    }

    private function cacheable(EventStrategy $events, CacheStrategy $cacheStrategy): CacheableService
    {
        $policy = $this->createMock(CachePolicy::class);
        $policy->method('getCacheKey')->willReturnCallback(static fn (array $context): string => serialize($context));
        $policy->method('getTtl')->willReturn(null);
        $policy->method('shouldCache')->willReturn(true);

        return new CacheableService($events, $cacheStrategy, $policy);
    }
}

class BridgeTestHandler extends DatabaseDatastoreHandler
{
    public string $marker = 'preserve-me';

    public function __construct(
        DatabaseServiceProvider $provider,
        \PHPNomad\Database\Interfaces\DatabaseContextProvider $contextProvider,
        \PHPNomad\Database\Services\TableSchemaService $schemaService,
        TableContract $table
    ) {
        parent::__construct($provider, $contextProvider, $schemaService);
        $this->table = $table;
        $this->model = BridgeTestModel::class;
        $this->modelAdapter = new BridgeTestModelAdapter();
    }
}

class BridgeTestModel implements \PHPNomad\Datastore\Interfaces\DataModel
{
    public function getIdentity(): array
    {
        return [];
    }
}

/** @implements \PHPNomad\Datastore\Interfaces\ModelAdapter<BridgeTestModel> */
class BridgeTestModelAdapter implements \PHPNomad\Datastore\Interfaces\ModelAdapter
{
    /** @param array<string, mixed> $array */
    public function toModel(array $array): BridgeTestModel
    {
        throw new \LogicException();
    }

    /** @return array<string, mixed> */
    public function toArray(\PHPNomad\Datastore\Interfaces\DataModel $model): array
    {
        throw new \LogicException();
    }
}

class BridgeProviderFactory implements \PHPNomad\Database\Interfaces\OperationDatabaseProviderFactory
{
    private Closure $factory;

    public function __construct(callable $factory)
    {
        $this->factory = Closure::fromCallable($factory);
    }

    public function create(
        \PHPNomad\Database\Interfaces\DatabaseHandler $handler,
        QueryStrategy $queryStrategy,
        OperationCacheableService $cache,
        OperationEventStrategy $events
    ): DatabaseServiceProvider {
        return ($this->factory)($handler, $queryStrategy, $cache, $events);
    }
}
