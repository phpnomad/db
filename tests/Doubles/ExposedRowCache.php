<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Database\Services\DatastoreRowCache;

/**
 * Exposes DatastoreRowCache's protected context builders so tests can
 * compute the exact cache slots the datastore uses (for exists() probes,
 * poisoning, and manual invalidation) without widening the production
 * contract.
 *
 * Tests normally avoid extending lib/ concretes — inheriting from package
 * classes couples tests to internals and is the shape the coding standards
 * steer away from. This subclass is the deliberate exception, because the
 * alternative is putting key plumbing back on the RowCache interface, which
 * the production contract intentionally narrowed away.
 */
class ExposedRowCache extends DatastoreRowCache
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function exposeRowContext(array $row): array
    {
        $context = $this->rowContext($row);

        if ($context === null) {
            throw new \RuntimeException('Test row cannot produce a full identity: ' . json_encode($row));
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $ids
     * @return array<string, mixed>
     */
    public function exposeAliasContext(array $ids): array
    {
        return $this->aliasContext($ids);
    }
}
