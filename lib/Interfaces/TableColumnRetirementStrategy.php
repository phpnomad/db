<?php

namespace PHPNomad\Database\Interfaces;

use PHPNomad\Database\Exceptions\TableUpdateFailedException;

/**
 * Optional, explicitly named retirement of legacy table columns.
 *
 * Implementations use their backend's identifier and case semantics. They
 * accept identifiers the backend can safely quote, reject empty or NUL-bearing
 * names, and treat metadata/query failures as failures rather than absence.
 */
interface TableColumnRetirementStrategy extends TableUpdateStrategy
{
    /**
     * Determine whether a named column exists in the table's active schema.
     *
     * Metadata lookup is scoped to the active schema. Identifier comparison
     * follows backend semantics (for example, MySQL column names compare
     * case-insensitively). Metadata failures must propagate; they are not
     * absence.
     *
     * @throws TableUpdateFailedException
     */
    public function columnExists(Table $table, string $columnName): bool;

    /**
     * Retire only the explicitly named columns.
     *
     * The request must contain at least one name, and the whole request must be
     * validated before any mutation. A requested column that is absent is an
     * idempotent no-op. A requested column that remains declared by the supplied
     * table, using backend case semantics, is invalid. Implementations must not
     * implicitly remove indexes or foreign keys: a requested column with either
     * dependency is refused before DDL. Only requested, currently present, and
     * fully preflighted columns may be retired; unrelated columns are preserved.
     *
     * @param non-empty-string ...$columnNames
     * @throws \InvalidArgumentException
     * @throws TableUpdateFailedException
     */
    public function retireColumns(Table $table, string ...$columnNames): void;
}
