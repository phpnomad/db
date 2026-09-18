<?php

namespace PHPNomad\Database\Tests\Unit\Contracts;

use Error;
use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Exceptions\ColumnNotFoundException;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\TestCase;
use RuntimeException;

/** Covers descriptor lookup. Later integration tests cover query and event publication. */
final class UncachedPrimarySchemaContractTest extends TestCase
{
    /**
     * @dataProvider primaryDeclarations
     * @param 'attribute'|'compound index'|'mixed declarations'|'unique only'|'no primary key' $kind
     */
    public function testReturnsDeclaredPrimaryMetadataWithoutCacheAccess(string $kind): void
    {
        $ordinary = new Column('score', 'BIGINT');
        $id = new Column('id', 'BIGINT', null, 'PRIMARY KEY', 'AUTO_INCREMENT');
        $tenant = new Column('tenantId', 'BIGINT');
        $plainId = new Column('id', 'BIGINT', null, 'AUTO_INCREMENT');
        [$columns, $indices, $expected] = match ($kind) {
            'attribute' => [[$ordinary, $id], [], [1 => $id]],
            'compound index' => [[$ordinary, $tenant, $plainId], [new Index(['tenantId', 'id'], null, 'PRIMARY KEY')], [$tenant, $plainId]],
            'mixed declarations' => [[$ordinary, $id, $tenant], [new Index(['tenantId'], null, 'PRIMARY KEY')], [$id, $tenant]],
            'unique only' => [[$ordinary, $plainId], [new Index(['id'], 'unique_id', 'UNIQUE')], []],
            'no primary key' => [[$ordinary], [], []],
        };
        $table = $this->createMock(Table::class);
        $table->method('getColumns')->willReturn($columns);
        $table->method('getIndices')->willReturn($indices);
        $schema = $this->withoutCacheAccess();

        self::assertSame($expected, $schema->getPrimaryColumnsForTableUncached($table));
    }

    public function testEveryCallUsesItsCurrentDescriptorEvenWhenPhysicalNamesMatch(): void
    {
        $firstColumn = new Column('firstId', 'BIGINT', null, 'PRIMARY KEY');
        $secondColumn = new Column('secondId', 'BIGINT', null, 'PRIMARY KEY');
        $first = $this->createMock(Table::class);
        $first->method('getName')->willReturn('same_physical_name');
        $first->method('getColumns')->willReturn([$firstColumn]);
        $first->method('getIndices')->willReturn([]);
        $second = $this->createMock(Table::class);
        $second->method('getName')->willReturn('same_physical_name');
        $second->method('getColumns')->willReturn([$secondColumn]);
        $second->method('getIndices')->willReturn([]);
        $schema = $this->withoutCacheAccess();

        self::assertSame([$firstColumn], $schema->getPrimaryColumnsForTableUncached($first));
        self::assertSame([$secondColumn], $schema->getPrimaryColumnsForTableUncached($second));
        self::assertSame([$firstColumn], $schema->getPrimaryColumnsForTableUncached($first));
    }

    /** @dataProvider uncachedLookups */
    public function testEachLookupReadsMetadataChangesOnTheSameDescriptor(string $lookup): void
    {
        $first = new Column('firstId', 'BIGINT', null, 'PRIMARY KEY');
        $second = new Column('secondId', 'VARCHAR', [64], 'PRIMARY KEY');
        $ordinary = new Column('score', 'BIGINT');
        $columns = [$ordinary, $first];
        $singular = 'first';
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('same_physical_name');
        $table->method('getColumns')->willReturnCallback(static function () use (&$columns): array { return $columns; });
        $table->method('getIndices')->willReturn([]);
        $table->method('getSingularUnprefixedName')->willReturnCallback(static function () use (&$singular): string { return $singular; });
        $schema = $this->withoutCacheAccess();

        $expected = match ($lookup) {
            'getPrimaryColumnsForTableUncached' => [1 => $first],
            'getPrimaryColumnNameForTableUncached' => $first,
            default => 'firstFirstId',
        };
        self::assertSame($expected, $schema->$lookup($table));

        $columns = [$second, $ordinary];
        $singular = 'second';
        $expected = match ($lookup) {
            'getPrimaryColumnsForTableUncached' => [$second],
            'getPrimaryColumnNameForTableUncached' => $second,
            default => 'secondSecondId',
        };
        self::assertSame($expected, $schema->$lookup($table));
    }

    /** @return array<string, array{string}> */
    public static function uncachedLookups(): array
    {
        return [
            'columns' => ['getPrimaryColumnsForTableUncached'],
            'primary column' => ['getPrimaryColumnNameForTableUncached'],
            'junction name' => ['getJunctionColumnNameFromTableUncached'],
        ];
    }

    /** @dataProvider descriptorFailures */
    public function testDescriptorFailuresPropagateUnchangedWithoutCacheAccess(string $method, string $kind, string $lookup): void
    {
        $original = $kind === 'error' ? new Error('Descriptor unavailable') : new RuntimeException('Descriptor unavailable');
        $table = $this->createMock(Table::class);
        if ($method !== 'getColumns') {
            $table->method('getColumns')->willReturn([new Column('id', 'BIGINT', null, 'PRIMARY KEY')]);
        }
        if ($method !== 'getIndices') {
            $table->method('getIndices')->willReturn([]);
        }
        $table->expects(self::once())->method($method)->willThrowException($original);
        $schema = $this->withoutCacheAccess();
        $caught = null;
        try {
            $schema->$lookup($table);
        } catch (RuntimeException|Error $failure) {
            $caught = $failure;
        }

        self::assertSame($original, $caught);
    }

    /** @dataProvider cachedLookups */
    public function testTheExistingCachedLookupStillUsesItsConfiguredCache(string $method): void
    {
        $column = new Column('cachedId', 'BIGINT', null, 'PRIMARY KEY');
        $cache = $this->createMock(CacheableService::class);
        $cache->expects(self::once())->method('getWithCache')->with(
            Operation::Read, ['for' => TableSchemaService::class, 'id' => 'existingPrimaryColumns'], self::isType('callable')
        )->willReturn([$column]);
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('existing');
        $table->method('getSingularUnprefixedName')->willReturn('existing');
        $table->expects(self::never())->method('getColumns');
        $table->expects(self::never())->method('getIndices');

        $expected = match ($method) {
            'getPrimaryColumnsForTable' => [$column],
            'getPrimaryColumnNameForTable' => $column,
            default => 'existingCachedId',
        };
        self::assertSame($expected, (new TableSchemaService($cache))->$method($table));
    }

    /** @return array<string, array{string}> */
    public static function cachedLookups(): array
    {
        return [
            'columns' => ['getPrimaryColumnsForTable'],
            'primary name' => ['getPrimaryColumnNameForTable'],
            'junction name' => ['getJunctionColumnNameFromTable'],
        ];
    }

    public function testUncachedNameHelpersPreserveTheColumnAndExistingJunctionNaming(): void
    {
        $column = new Column('externalKey', 'VARCHAR', [64], 'PRIMARY KEY');
        $table = $this->createMock(Table::class);
        $table->method('getColumns')->willReturn([$column]);
        $table->method('getIndices')->willReturn([]);
        $table->method('getSingularUnprefixedName')->willReturn('program');
        $schema = $this->withoutCacheAccess();

        self::assertSame($column, $schema->getPrimaryColumnNameForTableUncached($table));
        self::assertSame('programExternalKey', $schema->getJunctionColumnNameFromTableUncached($table));
    }

    /** @dataProvider invalidNameCardinality */
    public function testUncachedNameHelpersRetainTheExactlyOneColumnRule(string $method, bool $compound): void
    {
        $table = $this->createMock(Table::class);
        $table->method('getColumns')->willReturn($compound ? [new Column('tenantId', 'BIGINT'), new Column('id', 'BIGINT')] : []);
        $table->method('getIndices')->willReturn($compound ? [new Index(['tenantId', 'id'], null, 'PRIMARY KEY')] : []);
        $schema = $this->withoutCacheAccess();

        $this->expectException(ColumnNotFoundException::class);
        $this->expectExceptionMessage('Junction Tables must have exactly one primary key column.');
        $schema->$method($table);
    }

    public function testNameHelpersDoNotReuseAnotherDescriptorWithTheSamePhysicalName(): void
    {
        $firstColumn = new Column('firstId', 'BIGINT', null, 'PRIMARY KEY');
        $secondColumn = new Column('secondId', 'BIGINT', null, 'PRIMARY KEY');
        $first = $this->createMock(Table::class);
        $first->method('getName')->willReturn('same_physical_name');
        $first->method('getSingularUnprefixedName')->willReturn('first');
        $first->method('getColumns')->willReturn([$firstColumn]);
        $first->method('getIndices')->willReturn([]);
        $second = $this->createMock(Table::class);
        $second->method('getName')->willReturn('same_physical_name');
        $second->method('getSingularUnprefixedName')->willReturn('second');
        $second->method('getColumns')->willReturn([$secondColumn]);
        $second->method('getIndices')->willReturn([]);
        $schema = $this->withoutCacheAccess();

        self::assertSame($firstColumn, $schema->getPrimaryColumnNameForTableUncached($first));
        self::assertSame($secondColumn, $schema->getPrimaryColumnNameForTableUncached($second));
        self::assertSame('firstFirstId', $schema->getJunctionColumnNameFromTableUncached($first));
        self::assertSame('secondSecondId', $schema->getJunctionColumnNameFromTableUncached($second));
    }

    /** @return array<string, array{string, bool}> */
    public static function invalidNameCardinality(): array
    {
        return [
            'primary absent' => ['getPrimaryColumnNameForTableUncached', false],
            'primary compound' => ['getPrimaryColumnNameForTableUncached', true],
            'junction absent' => ['getJunctionColumnNameFromTableUncached', false],
            'junction compound' => ['getJunctionColumnNameFromTableUncached', true],
        ];
    }

    private function withoutCacheAccess(): TableSchemaService
    {
        $cache = $this->createMock(CacheableService::class);
        foreach (['getWithCache', 'get', 'set', 'delete', 'exists'] as $method) {
            $cache->expects(self::never())->method($method);
        }
        return new TableSchemaService($cache);
    }

    /** @return array<string, array{string}> */
    public static function primaryDeclarations(): array
    {
        return [
            'attribute' => ['attribute'], 'compound index' => ['compound index'],
            'mixed declarations' => ['mixed declarations'], 'unique only' => ['unique only'],
            'no primary key' => ['no primary key'],
        ];
    }

    /** @return array<string, array{string, string, string}> */
    public static function descriptorFailures(): array
    {
        $cases = [];
        foreach (['getPrimaryColumnsForTableUncached', 'getPrimaryColumnNameForTableUncached', 'getJunctionColumnNameFromTableUncached'] as $lookup) {
            foreach (['getColumns', 'getIndices'] as $method) {
                foreach (['exception', 'error'] as $kind) {
                    $cases[$lookup . ' ' . $method . ' ' . $kind] = [$method, $kind, $lookup];
                }
            }
        }
        foreach (['exception', 'error'] as $kind) {
            $cases['junction singular name ' . $kind] = ['getSingularUnprefixedName', $kind, 'getJunctionColumnNameFromTableUncached'];
        }
        return $cases;
    }
}
