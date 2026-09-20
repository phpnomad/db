<?php

namespace PHPNomad\Database\Tests\Unit\Abstracts;

use InvalidArgumentException;
use PHPNomad\Cache\Services\CacheableService;
use PHPNomad\Database\Abstracts\IdentifiableDatabaseDatastoreHandler;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Datastore\Interfaces\DatastoreHasIdentityQuery;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Logger\Interfaces\LoggerStrategy;

final class IdentifiableDatabaseDatastoreHandlerTest extends TestCase
{
    public function testHandlerAdvertisesIdentityQueryCapability(): void
    {
        [$handler] = $this->makeHandler(
            new RecordingIdentityQueryBuilder(),
            new RecordingIdentityClauseBuilder(),
            $this->createMock(QueryStrategy::class)
        );

        self::assertInstanceOf(DatastoreHasIdentityQuery::class, $handler);
    }

    public function testFindIdsResetsStateAndBuildsAnIdentityOnlyQuery(): void
    {
        $queryBuilder = new RecordingIdentityQueryBuilder();
        $clauseBuilder = new RecordingIdentityClauseBuilder();
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $expected = [['tenantId' => 10, 'recordId' => 42]];
        $queryStrategy->expects(self::once())
            ->method('query')
            ->with(self::identicalTo($queryBuilder))
            ->willReturn($expected);
        [$handler, $table] = $this->makeHandler($queryBuilder, $clauseBuilder, $queryStrategy);

        $result = $handler->findIds([[
            'type' => 'unexpected',
            'groupType' => 'unexpected',
            'clauses' => [[
                'column' => 'deletedAt',
                'operator' => 'IS NULL',
            ]],
        ]], 2, 3);

        self::assertSame($expected, $result);
        self::assertSame(1, $queryBuilder->resetCalls);
        self::assertSame($table, $queryBuilder->table);
        self::assertSame(['tenantId', 'recordId'], $queryBuilder->selectedFields);
        self::assertSame(2, $queryBuilder->limitValue);
        self::assertSame(3, $queryBuilder->offsetValue);
        self::assertSame(1, $clauseBuilder->resetCalls);
        self::assertCount(1, $clauseBuilder->groups);
        self::assertSame('AND', $clauseBuilder->groups[0]['logic']);
        self::assertInstanceOf(RecordingIdentityClauseBuilder::class, $clauseBuilder->groups[0]['builder']);
        self::assertSame([
            ['method' => 'where', 'field' => 'deletedAt', 'operator' => 'IS NULL', 'values' => []],
        ], $clauseBuilder->groups[0]['builder']->predicates);
        self::assertSame($clauseBuilder, $queryBuilder->whereClause);
    }

    public function testFindIdsAllowsAnUnfilteredQueryWithNullLimitAndZeroOffset(): void
    {
        $queryBuilder = new RecordingIdentityQueryBuilder();
        $clauseBuilder = new RecordingIdentityClauseBuilder();
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects(self::once())
            ->method('query')
            ->with(self::identicalTo($queryBuilder))
            ->willReturn([]);
        [$handler] = $this->makeHandler($queryBuilder, $clauseBuilder, $queryStrategy);

        self::assertSame([], $handler->findIds([], null, 0));
        self::assertSame(['tenantId', 'recordId'], $queryBuilder->selectedFields);
        self::assertNull($queryBuilder->limitValue);
        self::assertNull($queryBuilder->offsetValue);
        self::assertNull($queryBuilder->whereClause);
    }

    /**
     * @param array<array-key, mixed> $conditions
     * @dataProvider invalidIdentityQueries
     */
    public function testFindIdsRejectsInvalidInputBeforeQueryExecution(array $conditions, ?int $limit, ?int $offset): void
    {
        $queryBuilder = new RecordingIdentityQueryBuilder();
        $clauseBuilder = new RecordingIdentityClauseBuilder();
        $queryStrategy = $this->createMock(QueryStrategy::class);
        $queryStrategy->expects(self::never())->method('query');
        [$handler] = $this->makeHandler($queryBuilder, $clauseBuilder, $queryStrategy);

        try {
            $handler->findIds($conditions, $limit, $offset);
            self::fail('Expected invalid identity-query input to be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertNotSame('', $exception->getMessage());
        }

        self::assertSame(0, $queryBuilder->resetCalls);
        self::assertSame(0, $clauseBuilder->resetCalls);
    }

    /** @return array<string, array{array<array-key, mixed>, int|null, int|null}> */
    public static function invalidIdentityQueries(): array
    {
        $validClause = ['column' => 'id', 'operator' => '='];

        return [
            'zero limit' => [[], 0, null],
            'negative limit' => [[], -1, null],
            'negative offset' => [[], null, -1],
            'conditions are not a list' => [['group' => ['clauses' => [$validClause]]], null, null],
            'group is not an array' => [['group'], null, null],
            'group has no clauses' => [[[]], null, null],
            'group has empty clauses' => [[['clauses' => []]], null, null],
            'clauses are not a list' => [[['clauses' => ['first' => $validClause]]], null, null],
            'clause is not an array' => [[['clauses' => ['clause']]], null, null],
            'clause has no column' => [[['clauses' => [['operator' => '=']]]], null, null],
            'clause has no operator' => [[['clauses' => [['column' => 'id']]]], null, null],
            'operator is not a string' => [[['clauses' => [['column' => 'id', 'operator' => 3]]]], null, null],
            'operator is empty' => [[['clauses' => [['column' => 'id', 'operator' => '']]]], null, null],
            'column is not a string or list' => [[['clauses' => [['column' => 3, 'operator' => '=']]]], null, null],
            'column string is empty' => [[['clauses' => [['column' => '', 'operator' => '=']]]], null, null],
            'column list is empty' => [[['clauses' => [['column' => [], 'operator' => '=']]]], null, null],
            'columns are not a list' => [[['clauses' => [['column' => ['id' => 'id'], 'operator' => '=']]]], null, null],
            'column list contains non-string' => [[['clauses' => [['column' => ['id', 3], 'operator' => '=']]]], null, null],
            'column list contains empty string' => [[['clauses' => [['column' => ['id', ''], 'operator' => '=']]]], null, null],
            'type is not a string' => [[['type' => null, 'clauses' => [$validClause]]], null, null],
            'group type is not a string' => [[['groupType' => [], 'clauses' => [$validClause]]], null, null],
        ];
    }

    /**
     * @return array{IdentityQueryHandlerFixture, Table}
     */
    private function makeHandler(
        QueryBuilder $queryBuilder,
        ClauseBuilder $clauseBuilder,
        QueryStrategy $queryStrategy
    ): array {
        $table = $this->createMock(Table::class);
        $table->method('getFieldsForIdentity')->willReturn(['tenantId', 'recordId']);
        $serviceProvider = new DatabaseServiceProvider(
            $this->createMock(LoggerStrategy::class),
            $queryStrategy,
            $queryBuilder,
            $clauseBuilder,
            $this->createMock(CacheableService::class),
            $this->createMock(EventStrategy::class)
        );

        return [new IdentityQueryHandlerFixture($serviceProvider, $table), $table];
    }
}

final class IdentityQueryHandlerFixture extends IdentifiableDatabaseDatastoreHandler
{
    public function __construct(DatabaseServiceProvider $serviceProvider, Table $table)
    {
        $this->serviceProvider = $serviceProvider;
        $this->table = $table;
    }
}

final class RecordingIdentityQueryBuilder implements QueryBuilder
{
    public int $resetCalls = 0;

    /** @var list<string> */
    public array $selectedFields = ['staleField'];

    public ?Table $table = null;

    public ?ClauseBuilder $whereClause = null;

    public ?int $limitValue = 99;

    public ?int $offsetValue = 99;

    public function useTable(Table $table)
    {
        $this->table = $table;
        return $this;
    }

    public function select(string $field, string ...$fields)
    {
        $this->selectedFields = array_values(array_merge($this->selectedFields, [$field], $fields));
        return $this;
    }

    public function from(Table $table)
    {
        $this->table = $table;
        return $this;
    }

    public function where(?ClauseBuilder $clauseBuilder)
    {
        $this->whereClause = $clauseBuilder;
        return $this;
    }

    public function leftJoin(Table $table, string $column, string $onColumn)
    {
        return $this;
    }

    public function rightJoin(Table $table, string $column, string $onColumn)
    {
        return $this;
    }

    public function groupBy(string $column, string ...$columns)
    {
        return $this;
    }

    public function sum(string $fieldToSum, ?string $alias = null)
    {
        return $this;
    }

    public function count(string $fieldToCount, ?string $alias = null)
    {
        return $this;
    }

    public function limit(int $limit)
    {
        $this->limitValue = $limit;
        return $this;
    }

    public function offset(int $offset)
    {
        $this->offsetValue = $offset;
        return $this;
    }

    public function orderBy(string $field, string $order)
    {
        return $this;
    }

    public function build(): string
    {
        return 'identity query';
    }

    public function reset()
    {
        ++$this->resetCalls;
        $this->selectedFields = [];
        $this->table = null;
        $this->whereClause = null;
        $this->limitValue = null;
        $this->offsetValue = null;
        return $this;
    }

    public function resetClauses(string $clause, string ...$clauses)
    {
        return $this;
    }
}

final class RecordingIdentityClauseBuilder implements ClauseBuilder
{
    public int $resetCalls = 0;

    public ?Table $table = null;

    /** @var list<array{method: string, field: mixed, operator: string, values: array<array-key, mixed>}> */
    public array $predicates = [];

    /** @var list<array{method: string, logic: string, builder: ClauseBuilder}> */
    public array $groups = [];

    public function useTable(Table $table)
    {
        $this->table = $table;
        return $this;
    }

    public function where($field, string $operator, ...$values)
    {
        $this->predicates[] = [
            'method' => 'where',
            'field' => $field,
            'operator' => $operator,
            'values' => $values,
        ];
        return $this;
    }

    public function andWhere($field, string $operator, ...$values)
    {
        $this->predicates[] = [
            'method' => 'andWhere',
            'field' => $field,
            'operator' => $operator,
            'values' => $values,
        ];
        return $this;
    }

    public function orWhere($field, string $operator, ...$values)
    {
        $this->predicates[] = [
            'method' => 'orWhere',
            'field' => $field,
            'operator' => $operator,
            'values' => $values,
        ];
        return $this;
    }

    public function group(string $logic, ClauseBuilder ...$clauses)
    {
        foreach ($clauses as $builder) {
            $this->groups[] = ['method' => 'group', 'logic' => $logic, 'builder' => $builder];
        }
        return $this;
    }

    public function andGroup(string $logic, ClauseBuilder ...$clauses)
    {
        foreach ($clauses as $builder) {
            $this->groups[] = ['method' => 'andGroup', 'logic' => $logic, 'builder' => $builder];
        }
        return $this;
    }

    public function orGroup(string $logic, ClauseBuilder ...$clauses)
    {
        foreach ($clauses as $builder) {
            $this->groups[] = ['method' => 'orGroup', 'logic' => $logic, 'builder' => $builder];
        }
        return $this;
    }

    public function build(): string
    {
        return 'identity conditions';
    }

    public function reset()
    {
        ++$this->resetCalls;
        $this->predicates = [];
        $this->groups = [];
        return $this;
    }
}
