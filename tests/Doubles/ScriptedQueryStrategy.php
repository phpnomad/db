<?php

namespace PHPNomad\Database\Tests\Doubles;

use LogicException;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;

/**
 * Query strategy driven by an explicit queue of results. Each query()
 * consumes one queued result; running dry throws, which makes "this read
 * must be served from cache" an assertion by construction.
 */
class ScriptedQueryStrategy implements QueryStrategy
{
    /** @var array[] */
    private array $queryResults = [];
    public int $queryCount = 0;
    public int $estimatedCountValue = 0;
    /** @var array[] */
    public array $updates = [];
    /** @var array[] */
    public array $deletes = [];

    public function queueQueryResult(array $result): void
    {
        $this->queryResults[] = $result;
    }

    public function query(QueryBuilder $builder): array
    {
        $this->queryCount++;

        if (empty($this->queryResults)) {
            throw new LogicException('ScriptedQueryStrategy ran out of queued query results — unexpected query #' . $this->queryCount);
        }

        return array_shift($this->queryResults);
    }

    public function insert(Table $table, array $data): array
    {
        return ['id' => 1];
    }

    /** @var int|null 1-indexed delete call that should throw. */
    public ?int $throwOnDeleteCall = null;
    private int $deleteCalls = 0;

    public function delete(Table $table, array $ids): void
    {
        $this->deleteCalls++;

        if ($this->throwOnDeleteCall !== null && $this->deleteCalls === $this->throwOnDeleteCall) {
            throw new LogicException('Simulated SQL delete failure on call ' . $this->deleteCalls . '.');
        }

        $this->deletes[] = $ids;
    }

    public function update(Table $table, array $ids, array $data): void
    {
        $this->updates[] = [$ids, $data];
    }

    public function estimatedCount(Table $table): int
    {
        return $this->estimatedCountValue;
    }
}
