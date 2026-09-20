<?php

namespace PHPNomad\Database\Services;

use InvalidArgumentException;
use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\DatabaseHandler;
use PHPNomad\Database\Interfaces\OperationDatabaseProviderFactory;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Models\CoordinatedOperationResult;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use Throwable;
use Exception;

/**
 * Binds database datastore handler clones to one coordinated operation.
 *
 * The provider factory is the adapter seam for resource-bound builders. Its
 * result must use the supplied operation query strategy and local publication
 * services. The default factory clones the existing builder objects.
 */
class OperationDatabaseHandlerBridge
{
    private OperationDatabaseProviderFactory $providerFactory;

    public function __construct(OperationDatabaseProviderFactory $providerFactory)
    {
        $this->providerFactory = $providerFactory;
    }

    /**
     * @param array<string, int|string> $identity
     * @param array<int, Table> $participants
     * @param array<string|int, DatabaseHandler> $handlers
     * @param callable(array<string|int, DatabaseHandler>, QueryStrategy): mixed $operation
     * @return CoordinatedOperationResult
     */
    public function coordinate(
        CoordinatedQueryStrategy $coordinator,
        Table $coordinationTable,
        array $identity,
        array $participants,
        array $handlers,
        callable $operation
    ): CoordinatedOperationResult {
        if ($identity === []) {
            throw new InvalidArgumentException('An operation needs a non-empty identity.');
        }
        $this->validateParticipants($participants, $handlers, $coordinator);
        /** @var non-empty-array<string, int|string> $identity */
        /** @var non-empty-list<Table> $participants */
        /** @var non-empty-array<string|int, DatabaseHandler> $handlers */

        /** @var array<int, OperationCacheableService> $caches */
        $caches = [];
        /** @var array<int, OperationEventStrategy> $eventStrategies */
        $eventStrategies = [];
        $providerFactory = $this->providerFactory;

        try {
            $value = $coordinator->coordinate(
                $coordinationTable,
                $identity,
                $participants,
                function (QueryStrategy $queryStrategy) use (
                    $handlers,
                    $providerFactory,
                    &$caches,
                    &$eventStrategies,
                    $operation
                ) {
                    $localHandlers = [];
                    $queryBuilderIds = [];
                    $clauseBuilderIds = [];

                    foreach ($handlers as $key => $handler) {
                        $sourceProvider = $handler->getDatabaseServiceProvider();
                        $cacheKey = spl_object_id($sourceProvider->cacheableService);
                        $eventKey = spl_object_id($sourceProvider->eventStrategy);
                        $caches[$cacheKey] = $caches[$cacheKey] ?? new OperationCacheableService($sourceProvider->cacheableService);
                        $eventStrategies[$eventKey] = $eventStrategies[$eventKey] ?? new OperationEventStrategy($sourceProvider->eventStrategy);
                        $provider = $providerFactory->create($handler, $queryStrategy, $caches[$cacheKey], $eventStrategies[$eventKey]);
                        if (!$provider instanceof DatabaseServiceProvider) {
                            throw new InvalidArgumentException('The operation provider factory must return a database service provider.');
                        }
                        if ($provider->queryStrategy !== $queryStrategy) {
                            throw new InvalidArgumentException('The operation provider must use the operation query strategy.');
                        }
                        if (
                            $provider->cacheableService !== $caches[$cacheKey]
                            || $provider->eventStrategy !== $eventStrategies[$eventKey]
                            || $provider->loggerStrategy !== $sourceProvider->loggerStrategy
                        ) {
                            throw new InvalidArgumentException('The operation provider must use the operation-local publication services and source logger.');
                        }
                        if ($provider->queryBuilder === $sourceProvider->queryBuilder || $provider->clauseBuilder === $sourceProvider->clauseBuilder) {
                            throw new InvalidArgumentException('The operation provider must use independent query and clause builders.');
                        }
                        if (in_array(spl_object_id($provider->queryBuilder), $queryBuilderIds, true) || in_array(spl_object_id($provider->clauseBuilder), $clauseBuilderIds, true)) {
                            throw new InvalidArgumentException('Each operation handler must use independent query and clause builders.');
                        }
                        $queryBuilderIds[] = spl_object_id($provider->queryBuilder);
                        $clauseBuilderIds[] = spl_object_id($provider->clauseBuilder);
                        $provider->queryBuilder->reset();
                        $provider->clauseBuilder->reset();

                        $localHandler = $handler->cloneForOperation($provider);
                        if ($localHandler === $handler || $localHandler->getDatabaseServiceProvider() !== $provider) {
                            throw new InvalidArgumentException('The operation handler must be a clone with the operation provider.');
                        }
                        $localHandlers[$key] = $localHandler;
                    }

                    return $operation($localHandlers, $queryStrategy);
                }
            );
        } catch (Throwable $failure) {
            foreach ($caches as $cache) {
                $cache->discard();
            }
            foreach ($eventStrategies as $events) {
                $events->discard();
            }

            throw $failure;
        }

        $publicationFailures = [];
        foreach ($caches as $cache) {
            $publicationFailures = array_merge($publicationFailures, $cache->publishInvalidations());
        }
        foreach ($eventStrategies as $events) {
            $publicationFailures = array_merge($publicationFailures, $events->publish());
        }

        $logger = $handlers[array_key_first($handlers)]->getDatabaseServiceProvider()->loggerStrategy;
        foreach ($publicationFailures as $failure) {
            try {
                if ($failure instanceof Exception) {
                    $logger->logException($failure, 'Coordinated operation publication failed.');
                } else {
                    $logger->error('Coordinated operation publication failed.', ['exception' => get_class($failure)]);
                }
            } catch (Throwable $loggingFailure) {
                $publicationFailures[] = $loggingFailure;
            }
        }

        return new CoordinatedOperationResult($value, $publicationFailures);
    }

    /**
     * @param array<int, Table> $participants
     * @param array<string|int, DatabaseHandler> $handlers
     * @param CoordinatedQueryStrategy $coordinator
     */
    private function validateParticipants(array $participants, array $handlers, CoordinatedQueryStrategy $coordinator): void
    {
        if ($participants === [] || $handlers === []) {
            throw new InvalidArgumentException('An operation needs participants and handlers.');
        }

        $participantNames = [];
        $firstProvider = null;
        foreach ($participants as $participant) {
            if (!$participant instanceof Table || $participant->getName() === '') {
                throw new InvalidArgumentException('Operation participants must be named tables.');
            }
            $participantNames[] = $participant->getName();
        }

        foreach ($handlers as $handler) {
            if (!$handler instanceof DatabaseHandler) {
                throw new InvalidArgumentException('Operation handlers must be database handlers.');
            }

            $provider = $handler->getDatabaseServiceProvider();
            if ($provider->queryStrategy !== $coordinator) {
                throw new InvalidArgumentException('Every handler must use the coordinating query strategy.');
            }
            if ($firstProvider === null) {
                $firstProvider = $provider;
            } elseif (
                $provider->cacheableService !== $firstProvider->cacheableService
                || $provider->eventStrategy !== $firstProvider->eventStrategy
                || $provider->loggerStrategy !== $firstProvider->loggerStrategy
            ) {
                throw new InvalidArgumentException('Every handler must share the coordinating publication policy.');
            }

            if (!in_array($handler->getDatabaseTable()->getName(), $participantNames, true)) {
                throw new InvalidArgumentException('Every handler table must be a declared participant.');
            }
        }
    }
}
