<?php

namespace PHPNomad\Database\Strategies;

use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;

/** Architecture stub: one lifetime and declared-table boundary around a strategy. */
final class OperationQueryStrategy implements QueryStrategy
{
    /** @param non-empty-list<Table> $participants */
    public function __construct(QueryStrategy $delegate, array $participants)
    {
    }

    /**
     * Invalidate this handle permanently. Repeated closure is harmless.
     * This never commits, rolls back, or closes the delegate's connection.
     */
    public function close(): void
    {
    }

    /** @return array<array-key, mixed> */
    public function query(QueryBuilder $builder): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function insert(Table $table, array $data): array
    {
        return [];
    }

    /** @param array<string, int> $ids */
    public function delete(Table $table, array $ids): void
    {
    }

    /**
     * @param array<string, int> $ids
     * @param array<string, mixed> $data
     */
    public function update(Table $table, array $ids, array $data): void
    {
    }

    public function estimatedCount(Table $table): int
    {
        return 0;
    }
}
