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
    public function __construct(private array $row = [])
    {
    }

    public function get(string $field)
    {
        return $this->row[$field] ?? null;
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
