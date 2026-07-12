<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Database\Services\DatastoreRowCache;

/**
 * Exposes DatastoreRowCache's protected context builders so tests can
 * compute the exact cache slots the datastore uses (for exists() probes,
 * poisoning, and manual invalidation) without widening the production
 * contract.
 *
 * Deliberate test-only exception to the "don't extend lib/ concretes" rule:
 * the alternative is putting key plumbing back on the RowCache interface,
 * which the production contract intentionally narrowed away.
 */
class ExposedRowCache extends DatastoreRowCache
{
    public function exposeRowContext(array $row): ?array
    {
        return $this->rowContext($row);
    }

    public function exposeAliasContext(array $ids): array
    {
        return $this->aliasContext($ids);
    }
}
