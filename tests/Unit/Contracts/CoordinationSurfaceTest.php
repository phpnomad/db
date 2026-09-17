<?php

namespace PHPNomad\Database\Tests\Unit\Contracts;

use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Database\Exceptions\InactiveDatabaseOperationException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Interfaces\AtomicOperationStrategy;
use PHPNomad\Database\Interfaces\CoordinatedQueryStrategy;
use PHPNomad\Database\Interfaces\HasQueryTables;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Database\Tests\TestCase;
use ReflectionClass;

/** Surface compatibility only. Adapter behavior has a separate conformance gate. */
final class CoordinationSurfaceTest extends TestCase
{
    public function testExistingQueryImplementationNeedsNoNewCapability(): void
    {
        $legacy = new LegacyQueryStrategy();
        $table = $this->createMock(Table::class);

        self::assertInstanceOf(QueryStrategy::class, $legacy);
        self::assertNotInstanceOf(CoordinatedQueryStrategy::class, $legacy);
        self::assertSame(['id' => 7], $legacy->insert($table, ['name' => 'example']));
        self::assertSame([], $legacy->query($this->createMock(QueryBuilder::class)));
    }

    public function testExistingInterfacesKeepTheirMethodSets(): void
    {
        self::assertSame(
            ['delete', 'estimatedCount', 'insert', 'query', 'update'],
            $this->methodNames(QueryStrategy::class)
        );
        self::assertSame(
            ['build', 'count', 'from', 'groupBy', 'leftJoin', 'limit', 'offset',
                'orderBy', 'reset', 'resetClauses', 'rightJoin', 'select', 'sum',
                'useTable', 'where'],
            $this->methodNames(QueryBuilder::class)
        );
        self::assertSame(['atomic'], $this->methodNames(AtomicOperationStrategy::class));
        self::assertFalse(is_a(QueryBuilder::class, HasQueryTables::class, true));
    }

    public function testCoordinationIsAnOptionalQueryCapability(): void
    {
        self::assertTrue(is_a(CoordinatedQueryStrategy::class, QueryStrategy::class, true));
        self::assertSame(
            ['coordinate', 'delete', 'estimatedCount', 'insert', 'query', 'update'],
            $this->methodNames(CoordinatedQueryStrategy::class)
        );

        $method = (new ReflectionClass(CoordinatedQueryStrategy::class))->getMethod('coordinate');
        self::assertSame(4, $method->getNumberOfRequiredParameters());
        self::assertSame(
            ['coordinationTable', 'identity', 'participants', 'operation'],
            array_map(static fn ($parameter): string => $parameter->getName(), $method->getParameters())
        );
        self::assertSame(['getReferencedTables'], $this->methodNames(HasQueryTables::class));
    }

    public function testFailureOutcomesAreDistinctAndPreserveCauses(): void
    {
        $cause = new \RuntimeException('driver detail');
        $unsupported = new UnsupportedCoordinationException('unsupported', 0, $cause);
        $conflict = new CoordinatedOperationConflictException('rolled back', 0, $cause);
        $unknown = new CoordinatedOperationOutcomeUnknownException('unknown', 0, $cause);

        foreach ([$unsupported, $conflict, $unknown] as $error) {
            self::assertInstanceOf(DatastoreErrorException::class, $error);
            self::assertSame($cause, $error->getPrevious());
        }

        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $unknown);
        self::assertNotInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $conflict);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $unsupported);
        self::assertInstanceOf(\LogicException::class, new InactiveDatabaseOperationException('closed'));
    }

    /**
     * @param class-string $type
     * @return list<string>
     */
    private function methodNames(string $type): array
    {
        $names = array_map(
            static fn ($method): string => $method->getName(),
            (new ReflectionClass($type))->getMethods()
        );
        sort($names);

        return $names;
    }
}

/** A pre-capability adapter remains a valid implementation. */
final class LegacyQueryStrategy implements QueryStrategy
{
    /** @return array<array-key, mixed> */
    public function query(QueryBuilder $builder): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function insert(Table $table, array $data): array
    {
        return ['id' => 7];
    }

    /** @param array<string, int> $ids */
    public function delete(Table $table, array $ids): void
    {
    }

    /**
     * @param array<string, int> $ids
     * @param array<string, mixed> $data
     */
    public function update(Table $table, array $ids, array $data): void
    {
    }

    public function estimatedCount(Table $table): int
    {
        return 0;
    }
}
