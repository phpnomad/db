<?php

namespace PHPNomad\Database\Interfaces;

use PHPNomad\Database\Exceptions\TableUpdateFailedException;

/** Optional, explicitly named retirement of legacy table columns. */
interface TableColumnRetirementStrategy extends TableUpdateStrategy
{
    /**
     * Determine whether a named column exists in the table's active schema.
     *
     * Metadata failures must propagate; they are not absence.
     *
     * @throws TableUpdateFailedException
     */
    public function columnExists(Table $table, string $columnName): bool;

    /**
     * Retire only the explicitly named columns.
     *
     * The whole request must be validated before mutation. A requested column
     * that is absent is an idempotent no-op. A requested column that remains
     * declared by the supplied table is invalid.
     *
     * @param non-empty-string ...$columnNames
     * @throws \InvalidArgumentException
     * @throws TableUpdateFailedException
     */
    public function retireColumns(Table $table, string ...$columnNames): void;
}
