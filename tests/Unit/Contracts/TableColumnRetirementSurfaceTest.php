<?php

namespace PHPNomad\Database\Tests\Unit\Contracts;

use PHPNomad\Database\Exceptions\UnsupportedColumnRetirementException;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Interfaces\TableColumnRetirementStrategy;
use PHPNomad\Database\Interfaces\TableUpdateStrategy;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use ReflectionClass;

/** @coversNothing Public compatibility contract for optional retirement. */
final class TableColumnRetirementSurfaceTest extends TestCase
{
    public function testExistingTableUpdateImplementationNeedsNoNewCapability(): void
    {
        $legacy = new LegacyTableUpdateStrategy();

        self::assertInstanceOf(TableUpdateStrategy::class, $legacy);
        self::assertNotInstanceOf(TableColumnRetirementStrategy::class, $legacy);
    }

    public function testRetirementIsAnOptionalTableUpdateCapability(): void
    {
        self::assertTrue(is_a(TableColumnRetirementStrategy::class, TableUpdateStrategy::class, true));
        self::assertSame(
            ['columnExists', 'retireColumns', 'syncColumns'],
            $this->methodNames(TableColumnRetirementStrategy::class)
        );
    }

    public function testColumnExistsSignatureCarriesTableAndName(): void
    {
        $method = (new ReflectionClass(TableColumnRetirementStrategy::class))->getMethod('columnExists');

        self::assertSame('bool', (string) $method->getReturnType());
        self::assertSame(
            ['table', 'columnName'],
            array_map(static fn ($parameter): string => $parameter->getName(), $method->getParameters())
        );
    }

    public function testRetireColumnsSignatureIsVariadicAndReturnsVoid(): void
    {
        $method = (new ReflectionClass(TableColumnRetirementStrategy::class))->getMethod('retireColumns');
        $parameters = $method->getParameters();

        self::assertSame('void', (string) $method->getReturnType());
        self::assertSame(['table', 'columnNames'], array_map(
            static fn ($parameter): string => $parameter->getName(),
            $parameters
        ));
        self::assertTrue($parameters[1]->isVariadic());
    }

    public function testUnsupportedFailureIsARecoverableDatastoreFailure(): void
    {
        self::assertInstanceOf(
            DatastoreErrorException::class,
            new UnsupportedColumnRetirementException('No named-column retirement adapter is active.')
        );
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
final class LegacyTableUpdateStrategy implements TableUpdateStrategy
{
    public function syncColumns(Table $table): void
    {
    }
}
