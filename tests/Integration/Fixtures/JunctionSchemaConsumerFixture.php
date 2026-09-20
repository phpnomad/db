<?php

namespace PHPNomad\Database\Tests\Integration\Fixtures {
    use Exception;
    use PHPNomad\Cache\Exceptions\CachedItemNotFoundException;
    use PHPNomad\Cache\Interfaces\CachePolicy;
    use PHPNomad\Cache\Interfaces\CacheStrategy;
    use PHPNomad\Cache\Services\CacheableService;
    use PHPNomad\Database\Abstracts\JunctionTable;
    use PHPNomad\Database\Abstracts\Table;
    use PHPNomad\Database\Exceptions\ColumnNotFoundException;
    use PHPNomad\Database\Factories\Column;
    use PHPNomad\Database\Factories\Index;
    use PHPNomad\Database\Interfaces\HasCharsetProvider;
    use PHPNomad\Database\Interfaces\HasCollateProvider;
    use PHPNomad\Database\Interfaces\HasGlobalDatabasePrefix;
    use PHPNomad\Database\Interfaces\HasLocalDatabasePrefix;
    use PHPNomad\Database\Interfaces\Table as TableInterface;
    use PHPNomad\Database\Services\TableSchemaService;
    use PHPNomad\Events\Interfaces\Event;
    use PHPNomad\Events\Interfaces\EventStrategy;
    use PHPNomad\Logger\Interfaces\LoggerStrategy;

    final class JunctionSchemaConsumerFixture
    {
        private InMemoryCacheStrategy $cache;

        private TableSchemaService $schema;

        private JunctionTable $junction;

        public function __construct()
        {
            $this->cache = new InMemoryCacheStrategy();
            $cacheable = new CacheableService(
                new InMemoryEventStrategy(),
                $this->cache,
                new AlwaysCachePolicy()
            );
            $this->schema = new ConsumerTableSchemaService($cacheable);
            $prefixes = new FixturePrefixProvider();
            $encoding = new FixtureEncodingProvider();
            $programs = new ConsumerTable($prefixes, $prefixes, $encoding, $encoding, $this->schema, 'programs', 'program');
            $distributors = new ConsumerTable(
                $prefixes,
                $prefixes,
                $encoding,
                $encoding,
                $this->schema,
                'distributors',
                'distributor'
            );
            $this->junction = new ConsumerJunctionTable(
                $prefixes,
                $prefixes,
                $encoding,
                $encoding,
                $this->schema,
                $programs,
                $distributors,
                new IgnoringLoggerStrategy()
            );
        }

        public function junction(): JunctionTable
        {
            return $this->junction;
        }

        public function schema(): TableSchemaService
        {
            return $this->schema;
        }

        public function cacheEntryCount(): int
        {
            return $this->cache->entryCount();
        }
    }

    final class InMemoryCacheStrategy implements CacheStrategy
    {
        /** @var array<string, mixed> */
        private array $entries = [];

        /** @return mixed */
        public function get(string $key)
        {
            if (!$this->exists($key)) {
                throw new CachedItemNotFoundException($key);
            }

            return $this->entries[$key];
        }

        /** @param mixed $value */
        public function set(string $key, $value, ?int $ttl): void
        {
            $this->entries[$key] = $value;
        }

        public function delete(string $key): void
        {
            unset($this->entries[$key]);
        }

        public function exists(string $key): bool
        {
            return array_key_exists($key, $this->entries);
        }

        public function clear(): void
        {
            $this->entries = [];
        }

        public function entryCount(): int
        {
            return count($this->entries);
        }
    }

    final class AlwaysCachePolicy implements CachePolicy
    {
        /** @param array<mixed> $context */
        public function shouldCache(string $operation, array $context = []): bool
        {
            return true;
        }

        /** @param array<mixed> $context */
        public function getTtl(array $context = []): ?int
        {
            return null;
        }

        /** @param array<mixed> $context */
        public function shouldInvalidate(string $operation, array $context = []): bool
        {
            return false;
        }

        /** @param array<mixed> $context */
        public function getCacheKey(array $context): string
        {
            return sha1(serialize($context));
        }
    }

    final class InMemoryEventStrategy implements EventStrategy
    {
        /** @var array<string, list<array{action: callable, priority: int}>> */
        private array $listeners = [];

        public function broadcast(Event $event): void
        {
            $listeners = $this->listeners[$event::getId()] ?? [];
            usort(
                $listeners,
                static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']
            );

            foreach ($listeners as $listener) {
                ($listener['action'])($event);
            }
        }

        public function attach(string $event, callable $action, ?int $priority = null): void
        {
            $this->listeners[$event][] = [
                'action' => $action,
                'priority' => $priority ?? 10,
            ];
        }

        public function detach(string $event, callable $action, ?int $priority = null): void
        {
            $expectedPriority = $priority ?? 10;
            $this->listeners[$event] = array_values(array_filter(
                $this->listeners[$event] ?? [],
                static fn (array $listener): bool => $listener['action'] !== $action
                    || $listener['priority'] !== $expectedPriority
            ));
        }
    }

    final class FixturePrefixProvider implements HasLocalDatabasePrefix, HasGlobalDatabasePrefix
    {
        public function getLocalDatabasePrefix(): string
        {
            return 'local';
        }

        public function getGlobalDatabasePrefix(): string
        {
            return 'global';
        }
    }

    final class FixtureEncodingProvider implements HasCharsetProvider, HasCollateProvider
    {
        public function getCharset(): ?string
        {
            return null;
        }

        public function getCollation(): ?string
        {
            return null;
        }
    }

    final class ConsumerTableSchemaService extends TableSchemaService
    {
        public function getJunctionColumnNameFromTable(TableInterface $table): string
        {
            return 'custom' . ucfirst(parent::getJunctionColumnNameFromTable($table));
        }

        public function getPrimaryColumnNameForTable(TableInterface $table): Column
        {
            foreach ($table->getColumns() as $column) {
                if ($column->getName() === 'externalKey') {
                    return $column;
                }
            }

            throw new ColumnNotFoundException('The consumer identity column was not found.');
        }
    }

    final class ConsumerTable extends Table
    {
        private string $unprefixedName;

        private string $singularName;

        public function __construct(
            HasLocalDatabasePrefix $localPrefixProvider,
            HasGlobalDatabasePrefix $globalPrefixProvider,
            HasCharsetProvider $charsetProvider,
            HasCollateProvider $collateProvider,
            TableSchemaService $tableSchemaService,
            string $unprefixedName,
            string $singularName
        ) {
            parent::__construct(
                $localPrefixProvider,
                $globalPrefixProvider,
                $charsetProvider,
                $collateProvider,
                $tableSchemaService
            );
            $this->unprefixedName = $unprefixedName;
            $this->singularName = $singularName;
        }

        public function getAlias(): string
        {
            return $this->singularName;
        }

        public function getTableVersion(): string
        {
            return '1';
        }

        /** @return list<Column> */
        public function getColumns(): array
        {
            return [
                new Column('id', 'BIGINT', null, 'PRIMARY KEY'),
                new Column('externalKey', 'BIGINT'),
            ];
        }

        /** @return list<Index> */
        public function getIndices(): array
        {
            return [new Index(['externalKey'], null, 'UNIQUE')];
        }

        public function getUnprefixedName(): string
        {
            return $this->unprefixedName;
        }

        public function getSingularUnprefixedName(): string
        {
            return $this->singularName;
        }
    }

    final class ConsumerJunctionTable extends JunctionTable
    {
        public function getTableVersion(): string
        {
            return '1';
        }

        public function getSingularUnprefixedName(): string
        {
            return 'link';
        }
    }

    final class IgnoringLoggerStrategy implements LoggerStrategy
    {
        public function emergency(string $message, array $context = []): void
        {
        }

        public function alert(string $message, array $context = []): void
        {
        }

        public function critical(string $message, array $context = []): void
        {
        }

        public function error(string $message, array $context = []): void
        {
        }

        public function warning(string $message, array $context = []): void
        {
        }

        public function notice(string $message, array $context = []): void
        {
        }

        public function info(string $message, array $context = []): void
        {
        }

        public function debug(string $message, array $context = []): void
        {
        }

        /**
         * @param array<string, mixed> $context
         * @return null
         */
        public function logException(
            Exception $e,
            string $message = '',
            array $context = [],
            string $level = null
        ) {
            return null;
        }
    }
}
