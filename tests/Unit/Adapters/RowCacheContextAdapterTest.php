<?php

namespace PHPNomad\Database\Tests\Unit\Adapters;

use PHPNomad\Database\Adapters\RowCacheContextAdapter;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Tests\Doubles\FieldHidingModelAdapter;
use PHPNomad\Database\Tests\Doubles\IdentityRowModel;
use PHPNomad\Database\Tests\Doubles\IdentityRowModelAdapter;
use PHPNomad\Database\Tests\Doubles\OpaqueModelAdapter;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Datastore\Interfaces\DataModel;
use PHPNomad\Datastore\Interfaces\ModelAdapter;

/**
 * Focused coverage for the pure conversions the adapter owns — identity
 * derivation, context shapes, lookup verification — so a failure pinpoints
 * the vocabulary instead of a datastore scenario.
 */
class RowCacheContextAdapterTest extends TestCase
{
    /**
     * @param array<int, string> $identityFields
     * @param ModelAdapter<DataModel>|null $modelAdapter
     */
    private function makeAdapter(array $identityFields, bool $useGenerations = false, ?ModelAdapter $modelAdapter = null): RowCacheContextAdapter
    {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn('test_records');
        $table->method('getFieldsForIdentity')->willReturn($identityFields);

        return new RowCacheContextAdapter(
            $table,
            IdentityRowModel::class,
            $modelAdapter ?? new IdentityRowModelAdapter(),
            $useGenerations
        );
    }

    public function testRowContextIsTypeStableAcrossIntAndStringIdentities(): void
    {
        // Regression: MySQL returns identity columns as strings, but hydrated
        // rows can hold them as ints. Without normalization the same record
        // produces two distinct cache contexts and invalidation misses one.
        $adapter = $this->makeAdapter(['id']);

        $this->assertNotNull($adapter->toRowContext(['id' => 123], null));
        $this->assertSame(
            $adapter->toRowContext(['id' => 123], null),
            $adapter->toRowContext(['id' => '123'], null)
        );
    }

    public function testRowIdentityReordersToTableOrderAndStringifies(): void
    {
        $adapter = $this->makeAdapter(['orgId', 'id']);

        $this->assertSame(
            ['orgId' => '1', 'id' => '42'],
            $adapter->toRowIdentity(['id' => 42, 'name' => 'extra', 'orgId' => 1])
        );
    }

    public function testRowIdentityIsNullWhenAFieldIsMissing(): void
    {
        // Pure conversion: null, no side effects — the datastore handler
        // owns the logging.
        $adapter = $this->makeAdapter(['orgId', 'id']);

        $this->assertNull($adapter->toRowIdentity(['id' => 42]));
    }

    public function testRawIdentityKeepsOriginalValueTypes(): void
    {
        // Raw projections feed SQL conditions, where cache stringification
        // must not leak into driver-typed comparisons.
        $adapter = $this->makeAdapter(['orgId', 'id']);

        $this->assertSame(
            ['orgId' => 1, 'id' => 42],
            $adapter->toRawIdentity(['id' => 42, 'orgId' => 1, 'name' => 'extra'])
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function identityShapes(): array
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
     *
     * @param array<string, mixed> $ids
     */
    public function testIsTableIdentityMatchesFieldSetsNotOrder(array $ids, bool $expected): void
    {
        $adapter = $this->makeAdapter(['orgId', 'id']);

        $this->assertSame($expected, $adapter->isTableIdentity($ids));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool|null}>
     */
    public static function lookupComparisons(): array
    {
        return [
            'same-type match' => [['id' => 7, 'keyHash' => 'abc'], ['keyHash' => 'abc'], true],
            'same-type mismatch' => [['id' => 7, 'keyHash' => 'abc'], ['keyHash' => 'xyz'], false],
            'int model vs string lookup' => [['id' => 7, 'keyHash' => 'abc'], ['id' => '7'], true],
            'string model vs int lookup' => [['id' => '7', 'keyHash' => 'abc'], ['id' => 7], true],
            'cross-type mismatch' => [['id' => 7, 'keyHash' => 'abc'], ['id' => '8'], false],
            'bool model vs stored zero' => [['id' => 7, 'active' => false], ['active' => '0'], true],
        ];
    }

    /**
     * @dataProvider lookupComparisons
     *
     * @param array<string, mixed> $modelRow
     * @param array<string, mixed> $lookup
     */
    public function testMatchesLookupComparesScalarsTypeInsensitively(array $modelRow, array $lookup, ?bool $expected): void
    {
        $adapter = $this->makeAdapter(['id']);

        $this->assertSame($expected, $adapter->matchesLookup(new IdentityRowModel($modelRow), $lookup));
    }

    public function testMatchesLookupIsNullWhenAnyFieldIsHidden(): void
    {
        // A field that cannot be checked is exactly the field a rotation may
        // have changed: matching exposed fields must not upgrade a partially
        // verifiable lookup to a verified match.
        $adapter = $this->makeAdapter(['id'], false, new FieldHidingModelAdapter(['tenantId']));

        $this->assertNull($adapter->matchesLookup(
            new IdentityRowModel(['id' => 7, 'keyHash' => 'abc', 'tenantId' => 9]),
            ['keyHash' => 'abc', 'tenantId' => 999999]
        ));
    }

    public function testMatchesLookupIsNullWhenNoFieldIsVerifiable(): void
    {
        // Tri-state: the adapter reports "unverifiable"; the datastore
        // handler owns the policy for what that means per generation mode.
        $adapter = $this->makeAdapter(['id'], false, new OpaqueModelAdapter());

        $this->assertNull($adapter->matchesLookup(new IdentityRowModel(['id' => 7, 'keyHash' => 'abc']), ['keyHash' => 'abc']));
    }

    public function testGenerationKeyedContextsCarryTheSnapshot(): void
    {
        $this->assertSame(
            ['type' => IdentityRowModel::class, 'table' => 'test_records', 'gen' => 'token-1'],
            $this->makeAdapter(['id'], true)->toTableContext('token-1')
        );
    }

    public function testContextsNeverCollideAcrossTablesSharingAModel(): void
    {
        // Nothing enforces a 1:1 model-to-table mapping: two tables reusing
        // one model class must never cross-serve rows whose identities
        // coincide, so every context carries the table name too.
        $other = $this->createMock(Table::class);
        $other->method('getName')->willReturn('other_records');
        $other->method('getFieldsForIdentity')->willReturn(['id']);

        $otherAdapter = new RowCacheContextAdapter($other, IdentityRowModel::class, new IdentityRowModelAdapter(), true);

        $this->assertNotSame(
            $this->makeAdapter(['id'], true)->toRowContext(['id' => '7'], 'token-1'),
            $otherAdapter->toRowContext(['id' => '7'], 'token-1')
        );
    }

    public function testGenerationKeyedTablesRefuseUnkeyedContexts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('generation snapshot is required');

        $this->makeAdapter(['id'], true)->toTableContext(null);
    }

    public function testGenerationDisabledTablesNeverCarryTokens(): void
    {
        $this->assertSame(
            ['type' => IdentityRowModel::class, 'table' => 'test_records'],
            $this->makeAdapter(['id'], false)->toTableContext('token-1')
        );
    }

    /**
     * The adapter owns token FORMATS; the datastore handler owns creation.
     *
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function generationTokenShapes(): array
    {
        return [
            'ephemeral-prefixed token' => ['ephemeral-abc123', true],
            'plain token' => ['abc123', false],
            'no token' => [null, false],
        ];
    }

    /**
     * @dataProvider generationTokenShapes
     */
    public function testEphemeralTokensAreRecognizedByPrefix(?string $token, bool $expected): void
    {
        $this->assertSame($expected, $this->makeAdapter(['id'], true)->isEphemeralGeneration($token));
    }

    public function testEphemeralMarkingRoundTrips(): void
    {
        $adapter = $this->makeAdapter(['id'], true);

        $this->assertTrue($adapter->isEphemeralGeneration($adapter->toEphemeralGeneration('abc123')));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function tokenValidityShapes(): array
    {
        return [
            'non-empty string' => ['abc123', true],
            'empty string' => ['', false],
            'null' => [null, false],
        ];
    }

    /**
     * @dataProvider tokenValidityShapes
     *
     * @param mixed $token
     */
    public function testTokenValidityRequiresANonEmptyString($token, bool $expected): void
    {
        $this->assertSame($expected, $this->makeAdapter(['id'], true)->isValidGeneration($token));
    }
}
