<?php

namespace PHPNomad\Database\Tests\Unit\Contracts;

use Error;
use PHPNomad\Cache\Enums\Operation;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Factories\Index;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Services\TableSchemaService;
use PHPNomad\Database\Tests\TestCase;
use RuntimeException;

/** Pure descriptor lookup. Real query and publication wiring has a later gate. */
final class UncachedPrimarySchemaContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestIncomplete('Uncached schema lookup implementation assignment is pending.');
    }

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

    /** @dataProvider descriptorFailures */
    public function testDescriptorFailuresPropagateUnchangedWithoutCacheAccess(string $method, string $kind): void
    {
        $original = $kind === 'error' ? new Error('Descriptor unavailable') : new RuntimeException('Descriptor unavailable');
        $table = $this->createMock(Table::class);
        $table->expects(self::once())->method($method)->willThrowException($original);
        $schema = $this->withoutCacheAccess();
        $caught = null;
        try {
            $schema->getPrimaryColumnsForTableUncached($table);
        } catch (RuntimeException|Error $failure) {
            $caught = $failure;
        }

        self::assertSame($original, $caught);
    }

    public function testTheExistingCachedLookupStillUsesItsConfiguredCache(): void
    {
        $column = new Column('cachedId', 'BIGINT', null, 'PRIMARY KEY');
        $cache = $this->createMock(CacheableService::class);
        $cache->expects(self::once())->method('getWithCache')->with(
            Operation::Read, ['for' => TableSchemaService::class, 'id' => 'existingPrimaryColumns'], self::isType('callable')
        )->willReturn([$column]);
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('existing');
        $table->expects(self::never())->method('getColumns');
        $table->expects(self::never())->method('getIndices');

        self::assertSame([$column], (new TableSchemaService($cache))->getPrimaryColumnsForTable($table));
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

    /** @return array<string, array{string, string}> */
    public static function descriptorFailures(): array
    {
        return [
            'columns exception' => ['getColumns', 'exception'], 'columns error' => ['getColumns', 'error'],
            'indices exception' => ['getIndices', 'exception'], 'indices error' => ['getIndices', 'error'],
        ];
    }
}
