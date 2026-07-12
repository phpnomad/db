<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Datastore\Interfaces\DataModel;

/**
 * Row-backed model whose own identity is deliberately a SUBSET of the table
 * identity (like a model that omits a tenant column) — the datastore must
 * never key the cache off it.
 */
class IdentityRowModel implements DataModel
{
    /**
     * @param array<string, mixed> $row
     */
    public function __construct(private array $row = [])
    {
    }

    /**
     * @return mixed
     */
    public function get(string $field)
    {
        return $this->row[$field] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return $this->row;
    }

    /**
     * @return array<string, mixed>
     */
    public function getIdentity(): array
    {
        return ['id' => $this->row['id'] ?? null];
    }
}
