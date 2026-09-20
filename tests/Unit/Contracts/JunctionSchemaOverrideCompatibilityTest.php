<?php

namespace PHPNomad\Database\Tests\Unit\Contracts;

use PHPNomad\Database\Abstracts\JunctionTable;
use PHPNomad\Database\Abstracts\Table;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\HasCharsetProvider;
use PHPNomad\Database\Interfaces\HasCollateProvider;
use PHPNomad\Database\Interfaces\HasGlobalDatabasePrefix;
use PHPNomad\Database\Interfaces\HasLocalDatabasePrefix;
use PHPNomad\Database\Interfaces\Table as TableInterface;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Logger\Interfaces\LoggerStrategy;
use PHPUnit\Framework\MockObject\MockObject;

final class JunctionSchemaOverrideCompatibilityTest extends TestCase
{
    /** @dataProvider sides */
    public function testColumnNamesUseTheExistingSchemaOverride(string $side): void
    {
        $left = $this->table('left');
        $right = $this->table('right');
        $schema = $this->schema();
        $schema->expects(self::once())->method('getJunctionColumnNameFromTable')
            ->with(self::identicalTo($side === 'left' ? $left : $right))
            ->willReturn('custom' . ucfirst($side) . 'Id');
        $junction = $this->junction($schema, $left, $right);

        self::assertSame('custom' . ucfirst($side) . 'Id', $side === 'left'
            ? $junction->getLeftColumnName() : $junction->getRightColumnName());
    }

    public function testForeignKeysUseTheExistingPrimaryColumnOverride(): void
    {
        $left = $this->table('left');
        $right = $this->table('right');
        $schema = $this->schema();
        /** @var list<TableInterface> $seen */
        $seen = [];
        $schema->method('getJunctionColumnNameFromTable')->willReturnCallback(
            static fn (TableInterface $table): string => $table === $left ? 'leftLink' : 'rightLink'
        );
        $schema->expects(self::exactly(2))->method('getPrimaryColumnNameForTable')->willReturnCallback(
            static function (TableInterface $table) use ($left, &$seen): Column {
                $seen[] = $table;
                return new Column($table === $left ? 'externalLeft' : 'externalRight', 'BIGINT');
            }
        );

        $indices = $this->junction($schema, $left, $right)->getIndices();

        self::assertCount(2, $seen);
        self::assertSame($left, $seen[0]);
        self::assertSame($right, $seen[1]);
        self::assertSame([
            ['FOREIGN KEY', ['leftLink'], ['REFERENCES lefts(externalLeft)']],
            ['FOREIGN KEY', ['rightLink'], ['REFERENCES rights(externalRight)']],
        ], array_map(static fn (Index $index): array => [
            $index->getType(), $index->getColumns(), $index->getAttributes(),
        ], array_slice($indices, 1)));
    }

    /** @return array<string, array{string}> */
    public static function sides(): array
    {
        return ['left lookup' => ['left'], 'right lookup' => ['right']];
    }

    /** @return TableSchemaService&MockObject */
    private function schema(): TableSchemaService
    {
        return $this->getMockBuilder(TableSchemaService::class)->disableOriginalConstructor()
            ->onlyMethods(['getJunctionColumnNameFromTable', 'getPrimaryColumnNameForTable'])->getMock();
    }

    private function table(string $name): Table
    {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn($name . 's');
        $table->method('getSingularUnprefixedName')->willReturn($name);
        $table->method('getColumns')->willReturn([new Column('id', 'BIGINT', null, 'PRIMARY KEY')]);
        $table->method('getIndices')->willReturn([]);
        return $table;
    }

    private function junction(TableSchemaService $schema, Table $left, Table $right): JunctionTable
    {
        return new class (
            $this->createMock(HasLocalDatabasePrefix::class),
            $this->createMock(HasGlobalDatabasePrefix::class),
            $this->createMock(HasCharsetProvider::class),
            $this->createMock(HasCollateProvider::class),
            $schema,
            $left,
            $right,
            $this->createMock(LoggerStrategy::class)
        ) extends JunctionTable {
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
}
