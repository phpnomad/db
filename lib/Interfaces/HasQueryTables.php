<?php

namespace PHPNomad\Database\Interfaces;

/** Optional source metadata for a query builder. */
interface HasQueryTables
{
    /**
     * Return the exact table objects referenced by the current query.
     *
     * Include the root and every join source, including aliases of one table.
     * Resetting or replacing query clauses must update this list. A query with
     * no root has no sources. This metadata must describe the built query,
     * not a separate caller-supplied list of intended tables.
     *
     * @return list<Table>
     */
    public function getReferencedTables(): array;
}
