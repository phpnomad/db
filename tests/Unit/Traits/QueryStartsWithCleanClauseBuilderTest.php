<?php

namespace PHPNomad\Database\Tests\Unit\Traits;

use Mockery;
use PHPNomad\Database\Interfaces\ClauseBuilder;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Database\Providers\DatabaseServiceProvider;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Database\Traits\WithDatastoreHandlerMethods;

final class QueryStartsWithCleanClauseBuilderTest extends TestCase
{
    public function testConditionsFromAQueryThatNeverBuiltDoNotCarryIntoTheNextQuery(): void
    {
        $clauseBuilder = new RecordingClauseBuilder();
        $handler = $this->makeHandler($clauseBuilder);

        // An earlier query adds a condition and never builds.
        $clauseBuilder->where('status', '=', 'draft');
        self::assertSame(['status'], $clauseBuilder->conditions);

        $handler->start();

        self::assertSame([], $clauseBuilder->conditions);
    }

    public function testFindIdsStartsWithoutConditionsFromAnEarlierQuery(): void
    {
        $clauseBuilder = new RecordingClauseBuilder();
        $handler = $this->makeHandler($clauseBuilder);

        $clauseBuilder->where('status', '=', 'draft');

        $handler->findIds([]);

        self::assertSame([], $clauseBuilder->conditions);
    }

    private function makeHandler(ClauseBuilder $clauseBuilder): object
    {
        $table = Mockery::mock(Table::class);
        $table->shouldReceive('getFieldsForIdentity')->andReturn(['id']);

        $queryBuilder = Mockery::mock(QueryBuilder::class);
        $queryBuilder->shouldReceive('reset', 'from', 'select', 'limit', 'offset', 'orderBy', 'where')->andReturnSelf();

        $provider = Mockery::mock(DatabaseServiceProvider::class)->makePartial();
        $provider->clauseBuilder = $clauseBuilder;
        $provider->queryBuilder = $queryBuilder;
        $provider->queryStrategy = Mockery::mock(\PHPNomad\Database\Interfaces\QueryStrategy::class)
            ->shouldReceive('query')->andReturn([])->getMock();

        return new class($provider, $table) {
            use WithDatastoreHandlerMethods;

            public function __construct($provider, $table)
            {
                $this->serviceProvider = $provider;
                $this->table = $table;
            }

            public function start(): void
            {
                $this->initiateQuery();
            }
        };
    }
}

final class RecordingClauseBuilder implements ClauseBuilder
{
    /** @var string[] */
    public array $conditions = [];

    public function useTable(Table $table) { return $this; }
    public function where($field, string $operator, ...$values) { $this->conditions[] = $field; return $this; }
    public function andWhere($field, string $operator, ...$values) { return $this->where($field, $operator, ...$values); }
    public function orWhere($field, string $operator, ...$values) { return $this->where($field, $operator, ...$values); }
    public function group(string $logic, ClauseBuilder ...$clauses) { return $this; }
    public function andGroup(string $logic, ClauseBuilder ...$clauses) { return $this; }
    public function orGroup(string $logic, ClauseBuilder ...$clauses) { return $this; }
    public function build(): string { return implode(',', $this->conditions); }
    public function reset() { $this->conditions = []; return $this; }
}
