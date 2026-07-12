<?php

namespace PHPNomad\Database\Tests\Unit\Services;

use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Tests\Doubles\ArrayCacheStrategy;
use PHPNomad\Database\Tests\Doubles\ExposedRowCache;
use PHPNomad\Database\Tests\Doubles\NullEventStrategy;
use PHPNomad\Database\Tests\Doubles\SerializingCachePolicy;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

/**
 * Focused unit coverage for DatastoreRowCache's pure helpers — identity
 * derivation, identity-shape checks, alias resolution, lookup verification —
 * so a failure pinpoints the component instead of a trait-level scenario.
 */
class DatastoreRowCacheTest extends TestCase
{
    private ArrayCacheStrategy $cacheStrategy;
    private CacheableService $cacheableService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheStrategy = new ArrayCacheStrategy();
        $this->cacheableService = new CacheableService(
            new NullEventStrategy(),
            $this->cacheStrategy,
            new SerializingCachePolicy()
        );
    }

    private function makeRowCache(
        array $identityFields,
        bool $useGenerations = false,
        ?LoggerStrategy $logger = null,
        ?ModelAdapter $adapter = null
    ): ExposedRowCache {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('test_records');
        $table->method('getFieldsForIdentity')->willReturn($identityFields);

        return new ExposedRowCache(
            $this->cacheableService,
            $logger ?? $this->createMock(LoggerStrategy::class),
            $table,
            RowModel::class,
            $adapter ?? new RowModelAdapter(),
            $useGenerations
        );
    }

    public function testRowIdentityReordersToTableOrderAndStringifies(): void
    {
        $rowCache = $this->makeRowCache(['orgId', 'id']);

        $this->assertSame(
            ['orgId' => '1', 'id' => '42'],
            $rowCache->rowIdentity(['id' => 42, 'name' => 'extra', 'orgId' => 1])
        );
    }

    public function testRowIdentityMissingFieldReturnsNullAndWarns(): void
    {
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('missing an identity field'), $this->arrayHasKey('missingField'));

        $rowCache = $this->makeRowCache(['orgId', 'id'], false, $logger);

        $this->assertNull($rowCache->rowIdentity(['id' => 42]));
    }

    public function testRowIdentityWithNoIdentityFieldsReturnsNull(): void
    {
        $rowCache = $this->makeRowCache([]);

        $this->assertNull($rowCache->rowIdentity(['id' => 42]));
    }

    /**
     * @return array<string, array{0: array, 1: bool}>
     */
    public function identityShapes(): array
    {
        return [
            'exact identity' => [['orgId' => '1', 'id' => '42'], true],
            'reordered identity' => [['id' => '42', 'orgId' => '1'], true],
            'subset' => [['id' => '42'], false],
            'superset' => [['orgId' => '1', 'id' => '42', 'name' => 'x'], false],
            'different fields' => [['keyHash' => 'abc', 'status' => 'active'], false],
            'empty' => [[], false],
        ];
    }

    /**
     * @dataProvider identityShapes
     */
    public function testIsTableIdentityMatchesFieldSetsNotOrder(array $ids, bool $expected): void
    {
        $rowCache = $this->makeRowCache(['orgId', 'id']);

        $this->assertSame($expected, $rowCache->isTableIdentity($ids));
    }

    public function testResolveAliasedIdentityRejectsMalformedValues(): void
    {
        $rowCache = $this->makeRowCache(['id']);

        // Something that is not a valid table identity sits under the alias
        // key (a bug, a poisoned cache, an old format) — it must never be
        // dereferenced as an identity.
        $this->cacheableService->set($rowCache->exposeAliasContext(['keyHash' => 'abc']), 'not-an-identity');

        $this->assertNull($rowCache->resolveAliasedIdentity(['keyHash' => 'abc']));
    }

    public function testResolveAliasedIdentityReturnsStoredIdentity(): void
    {
        $rowCache = $this->makeRowCache(['id']);

        $rowCache->storeAlias(['keyHash' => 'abc'], ['id' => '7']);

        $this->assertSame(['id' => '7'], $rowCache->resolveAliasedIdentity(['keyHash' => 'abc']));
    }

    /**
     * @return array<string, array{0: array, 1: array, 2: bool}>
     */
    public function lookupComparisons(): array
    {
        return [
            'same-type match' => [['id' => 7, 'keyHash' => 'abc'], ['keyHash' => 'abc'], true],
            'same-type mismatch' => [['id' => 7, 'keyHash' => 'abc'], ['keyHash' => 'xyz'], false],
            'int model vs string lookup' => [['id' => 7, 'keyHash' => 'abc'], ['id' => '7'], true],
            'string model vs int lookup' => [['id' => '7', 'keyHash' => 'abc'], ['id' => 7], true],
            'cross-type mismatch' => [['id' => 7, 'keyHash' => 'abc'], ['id' => '8'], false],
        ];
    }

    /**
     * @dataProvider lookupComparisons
     */
    public function testMatchesLookupComparesScalarsTypeInsensitively(array $modelRow, array $lookup, bool $expected): void
    {
        $rowCache = $this->makeRowCache(['id']);

        $this->assertSame($expected, $rowCache->matchesLookup(new RowModel($modelRow), $lookup));
    }

    public function testMatchesLookupTreatsUnverifiableFieldAsStaleWithoutGenerations(): void
    {
        $logger = $this->createMock(LoggerStrategy::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('could not be verified'), $this->arrayHasKey('field'));

        $rowCache = $this->makeRowCache(['id'], false, $logger, new HidingModelAdapter());

        $this->assertFalse(
            $rowCache->matchesLookup(new RowModel(['id' => 7, 'keyHash' => 'abc']), ['keyHash' => 'abc']),
            'An unverifiable lookup passed on a generation-disabled table — read-time verification is its only rotation defense.'
        );
    }

    public function testMatchesLookupSkipsUnverifiableFieldWithGenerations(): void
    {
        $rowCache = $this->makeRowCache(['id'], true, null, new HidingModelAdapter());

        $this->assertTrue(
            $rowCache->matchesLookup(new RowModel(['id' => 7, 'keyHash' => 'abc']), ['keyHash' => 'abc'])
        );
    }
}

class RowModel implements DataModel
{
    public function __construct(private array $row = [])
    {
    }

    public function toRow(): array
    {
        return $this->row;
    }

    public function getIdentity(): array
    {
        return ['id' => $this->row['id'] ?? null];
    }
}

class RowModelAdapter implements ModelAdapter
{
    public function toModel(array $array): DataModel
    {
        return new RowModel($array);
    }

    public function toArray(DataModel $model): array
    {
        return $model instanceof RowModel ? $model->toRow() : [];
    }
}

/**
 * Adapter that exposes nothing — the narrow-serialization case lookup
 * verification has to survive.
 */
class HidingModelAdapter implements ModelAdapter
{
    public function toModel(array $array): DataModel
    {
        return new RowModel($array);
    }

    public function toArray(DataModel $model): array
    {
        return [];
    }
}
