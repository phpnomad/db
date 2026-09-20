<?php

namespace PHPNomad\Database\Tests\Unit\Contracts;

use Error;
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
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

/** Wires JunctionTable through the established TableSchemaService extension points. */
final class JunctionSchemaContractTest extends TestCase
{
    private TableSchemaService $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $cache = $this->createMock(CacheableService::class);
        $cache->method('getWithCache')->willReturnCallback(
            static fn (string $operation, array $context, callable $callback) => $callback()
        );
        $this->schema = new TableSchemaService($cache);
    }

    public function testBuiltInJunctionMetadataPreservesNamesAndIndices(): void
    {
        $left = $this->table('program', 'externalKey');
        $right = $this->table('distributor', 'id');
        $junction = $this->junction($left, $right);

        self::assertSame($left, $junction->getLeftTable());
        self::assertSame($right, $junction->getRightTable());
        self::assertSame('programExternalKey', $junction->getLeftColumnName());
        self::assertSame('distributorId', $junction->getRightColumnName());
        self::assertSame(['programExternalKey', 'distributorId'], $junction->getFieldsForIdentity());
        self::assertSame([
            ['programExternalKey', 'BIGINT'], ['distributorId', 'BIGINT'],
        ], array_map(static fn (Column $column): array => [$column->getName(), $column->getType()], $junction->getColumns()));
        self::assertSame([
            ['PRIMARY KEY', ['distributorId', 'programExternalKey'], []],
            ['FOREIGN KEY', ['programExternalKey'], ['REFERENCES global_local_programs(externalKey)']],
            ['FOREIGN KEY', ['distributorId'], ['REFERENCES global_local_distributors(id)']],
        ], array_map(static fn (Index $index): array => [$index->getType(), $index->getColumns(), $index->getAttributes()], $junction->getIndices()));
        self::assertSame(['programExternalKey', 'distributorId'], array_map(
            static fn (Column $column): string => $column->getName(),
            $this->schema->getPrimaryColumnsForTableUncached($junction)
        ));
    }

    public function testNestedJunctionMetadataRetainsItsCompoundKeyRefusal(): void
    {
        $inner = $this->junction($this->table('program', 'id'), $this->table('distributor', 'id'));
        $outer = $this->junction($inner, $this->table('account', 'id'));

        $this->expectException(ColumnNotFoundException::class);
        $this->expectExceptionMessage('Junction Tables must have exactly one primary key column.');
        $outer->getColumns();
    }

    /** @dataProvider descriptorFailures */
    public function testLeafDescriptorFailureReachesTheCallerUnchanged(string $kind, string $side): void
    {
        $failure = $kind === 'error' ? new Error('Leaf metadata failure') : new RuntimeException('Leaf metadata failure');
        $left = $this->table('program', 'id', $side !== 'left');
        $right = $this->table('distributor', 'id', $side !== 'right');
        $leaf = $side === 'left' ? $left : $right;
        $leaf->expects(self::once())->method('getColumns')->willThrowException($failure);
        $junction = $this->junction($left, $right);
        $caught = null;
        try {
            $junction->getColumns();
        } catch (RuntimeException|Error $actual) {
            $caught = $actual;
        }
        self::assertSame($failure, $caught);
    }

    /** @return array<string, array{string, string}> */
    public static function descriptorFailures(): array
    {
        return [
            'left exception' => ['exception', 'left'], 'left error' => ['error', 'left'],
            'right exception' => ['exception', 'right'], 'right error' => ['error', 'right'],
        ];
    }

    /** @return Table&MockObject */
    private function table(string $singular, string $identity, bool $withColumns = true): Table
    {
        $table = $this->getMockForAbstractClass(Table::class, $this->tableArguments());
        $table->method('getUnprefixedName')->willReturn($singular . 's');
        $table->method('getSingularUnprefixedName')->willReturn($singular);
        if ($withColumns) {
            $table->method('getColumns')->willReturn([new Column($identity, 'BIGINT', null, 'PRIMARY KEY')]);
        }
        $table->method('getIndices')->willReturn([]);
        return $table;
    }

    private function junction(Table $left, Table $right): JunctionTable
    {
        $arguments = [...$this->tableArguments(), $left, $right, $this->createMock(LoggerStrategy::class)];
        return new class (...$arguments) extends JunctionTable {
            public function getTableVersion(): string
            {
                return '1';
            }
            public function getSingularUnprefixedName(): string
            {
                return 'link';
            }
        };
    }

    /** @return array{HasLocalDatabasePrefix, HasGlobalDatabasePrefix, HasCharsetProvider, HasCollateProvider, TableSchemaService} */
    private function tableArguments(): array
    {
        $local = $this->createMock(HasLocalDatabasePrefix::class);
        $local->method('getLocalDatabasePrefix')->willReturn('local');
        $global = $this->createMock(HasGlobalDatabasePrefix::class);
        $global->method('getGlobalDatabasePrefix')->willReturn('global');
        return [$local, $global, $this->createMock(HasCharsetProvider::class), $this->createMock(HasCollateProvider::class), $this->schema];
    }
}
