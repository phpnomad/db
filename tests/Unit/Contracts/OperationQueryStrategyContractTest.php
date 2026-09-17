<?php

namespace PHPNomad\Database\Tests\Unit\Contracts;

use InvalidArgumentException;
use PHPNomad\Database\Exceptions\InactiveDatabaseOperationException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Interfaces\HasQueryTables;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Strategies\OperationQueryStrategy;
use PHPNomad\Database\Tests\TestCase;
use RuntimeException;

final class OperationQueryStrategyContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->markTestIncomplete('OperationQueryStrategy implementation packet is pending.');
    }

    /** @dataProvider methods */
    public function testEveryMethodForwardsExactArgumentsAndResults(string $method): void
    {
        $table = $this->table('scores');
        $builder = $this->builder([$table]);
        $arguments = $this->arguments($method, $table, $builder);
        $result = $this->result($method);
        $delegate = $this->createMock(QueryStrategy::class);
        $expectation = $delegate->expects(self::once())->method($method)
            ->with(...array_map(static fn ($argument) => self::identicalTo($argument), $arguments));
        if ($method !== 'delete' && $method !== 'update') {
            $expectation->willReturn($result);
        }

        $operation = new OperationQueryStrategy($delegate, [$table]);

        self::assertSame($result, $operation->$method(...$arguments));
    }

    /** @dataProvider methods */
    public function testEveryMethodPreservesTheOriginalFailureWithoutRetry(string $method): void
    {
        $table = $this->table('scores');
        $arguments = $this->arguments($method, $table, $this->builder([$table]));
        $failure = new RuntimeException('original failure');
        $delegate = $this->createMock(QueryStrategy::class);
        $delegate->expects(self::once())->method($method)
            ->with(...array_map(static fn ($argument) => self::identicalTo($argument), $arguments))
            ->willThrowException($failure);
        $operation = new OperationQueryStrategy($delegate, [$table]);

        try {
            $operation->$method(...$arguments);
            self::fail('Expected the original failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    /** @dataProvider methods */
    public function testEveryMethodRejectsAnUndeclaredTableBeforeDelegation(string $method): void
    {
        $outside = $this->table('outside');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$this->table('scores')]);

        $this->expectException(InvalidArgumentException::class);
        $operation->$method(...$this->arguments($method, $outside, $this->builder([$outside])));
    }

    /** @dataProvider methods */
    public function testEveryMethodRejectsUseAfterCloseBeforeInspectingArguments(string $method): void
    {
        $table = $this->createMock(Table::class);
        $table->expects(self::never())->method('getName');
        $builder = $this->createMock(InspectableQueryBuilder::class);
        $builder->expects(self::never())->method('getReferencedTables');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$this->table('scores')]);
        $operation->close();
        $operation->close();

        $this->expectException(InactiveDatabaseOperationException::class);
        $operation->$method(...$this->arguments($method, $table, $builder));
    }

    public function testUninspectableBuilderIsUnsupportedBeforeBuildOrDelegation(): void
    {
        $builder = $this->createMock(QueryBuilder::class);
        $builder->expects(self::never())->method('build');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$this->table('scores')]);

        $this->expectException(UnsupportedCoordinationException::class);
        $operation->query($builder);
    }

    public function testMetadataFailurePropagatesBeforeBuildOrDelegation(): void
    {
        $cause = new RuntimeException('query descriptors changed');
        $builder = $this->createMock(InspectableQueryBuilder::class);
        $builder->expects(self::once())->method('getReferencedTables')->willThrowException($cause);
        $builder->expects(self::never())->method('build');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$this->table('scores')]);

        try {
            $operation->query($builder);
            self::fail('Metadata failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($cause, $caught);
        }
    }

    /** @dataProvider undeclaredSourcePositions */
    public function testEveryQuerySourceMustBeDeclaredRegardlessOfPosition(int $position): void
    {
        $scores = $this->table('scores');
        $allowedJoin = $this->table('programs');
        $outsideJoin = $this->table('private_accounts');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$scores, $allowedJoin]);
        $sources = [$scores, $allowedJoin];
        array_splice($sources, $position, 0, [$outsideJoin]);

        $this->expectException(InvalidArgumentException::class);
        $operation->query($this->builder($sources));
    }

    public function testAllowedRootAndJoinsDelegateTogether(): void
    {
        $scores = $this->table('scores');
        $programs = $this->table('programs');
        $builder = $this->builder([$scores, $programs]);
        $delegate = $this->createMock(QueryStrategy::class);
        $delegate->expects(self::once())->method('query')->with(self::identicalTo($builder))->willReturn([['score' => '12']]);
        $operation = new OperationQueryStrategy($delegate, [$scores, $programs]);

        self::assertSame([['score' => '12']], $operation->query($builder));
    }

    public function testAReusedBuilderIsInspectedAgain(): void
    {
        $table = $this->table('scores');
        $builder = $this->createMock(InspectableQueryBuilder::class);
        $builder->expects(self::exactly(2))->method('getReferencedTables')
            ->willReturnOnConsecutiveCalls([$table], [$table, $this->table('outside')]);
        $delegate = $this->createMock(QueryStrategy::class);
        $delegate->expects(self::once())->method('query')->with(self::identicalTo($builder))->willReturn([['id' => '7']]);
        $operation = new OperationQueryStrategy($delegate, [$table]);
        self::assertSame([['id' => '7']], $operation->query($builder));

        $this->expectException(InvalidArgumentException::class);
        $operation->query($builder);
    }

    public function testRepeatedQueriesReturnFreshDelegateResults(): void
    {
        $table = $this->table('scores');
        $builder = $this->builder([$table]);
        $delegate = $this->createMock(QueryStrategy::class);
        $delegate->expects(self::exactly(2))->method('query')->with(self::identicalTo($builder))
            ->willReturnOnConsecutiveCalls([['score' => '12']], [['score' => '15']]);
        $operation = new OperationQueryStrategy($delegate, [$table]);

        self::assertSame([['score' => '12']], $operation->query($builder));
        self::assertSame([['score' => '15']], $operation->query($builder));
    }

    public function testAliasesAndDuplicateDeclarationsReferToTheSamePhysicalTable(): void
    {
        $table = $this->table('scores', 's');
        $alias = $this->table('scores', 'other_alias');
        $delegate = $this->createMock(QueryStrategy::class);
        $delegate->expects(self::once())->method('estimatedCount')->with(self::identicalTo($alias))->willReturn(8);
        $operation = new OperationQueryStrategy($delegate, [$table, $alias]);

        self::assertSame(8, $operation->estimatedCount($alias));
    }

    /** @dataProvider methods */
    public function testOtherAliasesNeedNoSeparateParticipantDeclaration(string $method): void
    {
        $declared = $this->table('scores', 's');
        $alias = $this->table('scores', 'undeclared_alias');
        $builder = $this->builder([$alias]);
        $arguments = $this->arguments($method, $alias, $builder);
        $result = $this->result($method);
        $delegate = $this->createMock(QueryStrategy::class);
        $expectation = $delegate->expects(self::once())->method($method)
            ->with(...array_map(static fn ($argument) => self::identicalTo($argument), $arguments));
        if ($method !== 'delete' && $method !== 'update') {
            $expectation->willReturn($result);
        }
        $operation = new OperationQueryStrategy($delegate, [$declared]);

        self::assertSame($result, $operation->$method(...$arguments));
    }

    public function testTableNamesAreComparedExactly(): void
    {
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$this->table('scores')]);

        $this->expectException(InvalidArgumentException::class);
        $operation->estimatedCount($this->table('Scores'));
    }

    /** @dataProvider methods */
    public function testNumericLookingTableNamesRemainDistinctForEveryMethod(string $method): void
    {
        $outside = $this->table('0e123');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$this->table('0')]);

        $this->expectException(InvalidArgumentException::class);
        $operation->$method(...$this->arguments($method, $outside, $this->builder([$outside])));
    }

    public function testChangingAParticipantDescriptorCannotAddAnAllowedName(): void
    {
        $name = 'scores';
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturnCallback(static function () use (&$name): string {
            return $name;
        });
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$table]);
        $name = 'private_accounts';

        $this->expectException(InvalidArgumentException::class);
        $operation->estimatedCount($table);
    }

    public function testChangingADescriptorDoesNotRemoveTheOriginallyAllowedName(): void
    {
        $name = 'scores';
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturnCallback(static function () use (&$name): string {
            return $name;
        });
        $original = $this->table('scores');
        $delegate = $this->createMock(QueryStrategy::class);
        $delegate->expects(self::once())->method('estimatedCount')->with(self::identicalTo($original))->willReturn(3);
        $operation = new OperationQueryStrategy($delegate, [$table]);
        $name = 'private_accounts';

        self::assertSame(3, $operation->estimatedCount($original));
    }

    /** @dataProvider malformedLists */
    public function testInvalidParticipantListsAreRejected(array $participants): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OperationQueryStrategy($this->unusedDelegate(), $participants);
    }

    public function testAnEmptyParticipantNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OperationQueryStrategy($this->unusedDelegate(), [$this->table('')]);
    }

    /**
     * @dataProvider invalidElementsAtEveryPosition
     * @param mixed $invalid
     */
    public function testEveryParticipantElementMustBeATable($invalid, int $position): void
    {
        $participants = [$this->table('scores'), $this->table('programs')];
        array_splice($participants, $position, 0, [$invalid]);
        $this->expectException(InvalidArgumentException::class);
        new OperationQueryStrategy($this->unusedDelegate(), $participants);
    }

    /** @dataProvider undeclaredSourcePositions */
    public function testEveryParticipantNameMustBeNonempty(int $position): void
    {
        $participants = [$this->table('scores'), $this->table('programs')];
        array_splice($participants, $position, 0, [$this->table('')]);
        $this->expectException(InvalidArgumentException::class);
        new OperationQueryStrategy($this->unusedDelegate(), $participants);
    }

    public function testParticipantsMustBeAList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OperationQueryStrategy($this->unusedDelegate(), ['named' => $this->table('scores')]);
    }

    public function testParticipantListsCannotHaveIndexGaps(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OperationQueryStrategy($this->unusedDelegate(), [0 => $this->table('scores'), 2 => $this->table('programs')]);
    }

    /** @dataProvider malformedLists */
    public function testInvalidQuerySourcesAreRejected(array $sources): void
    {
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$this->table('scores')]);

        $this->expectException(InvalidArgumentException::class);
        $operation->query($this->builder($sources));
    }

    public function testQuerySourcesMustBeAList(): void
    {
        $table = $this->table('scores');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$table]);

        $this->expectException(InvalidArgumentException::class);
        $operation->query($this->builder(['named' => $table]));
    }

    /**
     * @dataProvider invalidElementsAtEveryPosition
     * @param mixed $invalid
     */
    public function testEveryQuerySourceElementMustBeATable($invalid, int $position): void
    {
        $table = $this->table('scores');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$table]);
        $sources = [$table, $table];
        array_splice($sources, $position, 0, [$invalid]);

        $this->expectException(InvalidArgumentException::class);
        $operation->query($this->builder($sources));
    }

    /** @dataProvider undeclaredSourcePositions */
    public function testEveryQuerySourceNameMustBeNonempty(int $position): void
    {
        $table = $this->table('scores');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$table]);
        $sources = [$table, $table];
        array_splice($sources, $position, 0, [$this->table('')]);

        $this->expectException(InvalidArgumentException::class);
        $operation->query($this->builder($sources));
    }

    public function testQuerySourceListsCannotHaveIndexGaps(): void
    {
        $scores = $this->table('scores');
        $programs = $this->table('programs');
        $operation = new OperationQueryStrategy($this->unusedDelegate(), [$scores, $programs]);

        $this->expectException(InvalidArgumentException::class);
        $operation->query($this->builder([0 => $scores, 2 => $programs]));
    }

    /** @return array<string, array{string}> */
    public static function methods(): array
    {
        return [
            'query' => ['query'], 'insert' => ['insert'], 'update' => ['update'],
            'delete' => ['delete'], 'estimated count' => ['estimatedCount'],
        ];
    }

    /** @return array<string, array{int}> */
    public static function undeclaredSourcePositions(): array
    {
        return ['root' => [0], 'middle join' => [1], 'last join' => [2]];
    }

    /** @return array<string, array{array<mixed>}> */
    public static function malformedLists(): array
    {
        return ['empty' => [[]], 'not tables' => [['scores']], 'null element' => [[null]]];
    }

    /** @return array<string, array{mixed, int}> */
    public static function invalidElementsAtEveryPosition(): array
    {
        return [
            'first string' => ['scores', 0], 'middle string' => ['scores', 1], 'last string' => ['scores', 2],
            'first null' => [null, 0], 'middle null' => [null, 1], 'last null' => [null, 2],
            'first object' => [new \stdClass(), 0], 'middle object' => [new \stdClass(), 1],
            'last object' => [new \stdClass(), 2],
        ];
    }

    private function table(string $name, string $alias = 't'): Table
    {
        $table = $this->createMock(Table::class);
        $table->method('getName')->willReturn($name);
        $table->method('getAlias')->willReturn($alias);
        return $table;
    }

    /** @param array<mixed> $tables */
    private function builder(array $tables): InspectableQueryBuilder
    {
        $builder = $this->createMock(InspectableQueryBuilder::class);
        $builder->method('getReferencedTables')->willReturn($tables);
        return $builder;
    }

    private function unusedDelegate(): QueryStrategy
    {
        $delegate = $this->createMock(QueryStrategy::class);
        foreach (array_keys(self::methods()) as $label) {
            $method = $label === 'estimated count' ? 'estimatedCount' : $label;
            $delegate->expects(self::never())->method($method);
        }
        return $delegate;
    }

    /** @return list<mixed> */
    private function arguments(string $method, Table $table, QueryBuilder $builder): array
    {
        if ($method === 'query') {
            return [$builder];
        }
        if ($method === 'insert') {
            return [$table, ['score' => -2, 'reason' => null]];
        }
        if ($method === 'delete') {
            return [$table, ['id' => 7, 'tenantId' => 9]];
        }
        if ($method === 'update') {
            return [$table, ['id' => 7, 'tenantId' => 9], ['score' => 0]];
        }
        return [$table];
    }

    /** @return array<mixed>|int|null */
    private function result(string $method)
    {
        if ($method === 'query') {
            return [['id' => '7', 'score' => '-2', 'reason' => null]];
        }
        if ($method === 'insert') {
            return ['id' => 7, 'tenantId' => 9];
        }
        return $method === 'estimatedCount' ? 8 : null;
    }
}

interface InspectableQueryBuilder extends QueryBuilder, HasQueryTables
{
}
