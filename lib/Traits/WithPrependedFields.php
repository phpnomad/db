<?php

namespace PHPNomad\Database\Traits;

use PHPNomad\Database\Interfaces\Table;

trait WithPrependedFields
{
    use WithUseTable;

    /**
     * Prepends the specified field with the current table's alias.
     *
     * @param string $field
     * @param ?Table $table
     * @return string
     */
    protected function prependField(string $field, ?Table $table = null): string
    {
        $table = $table ?? $this->table;

        return $table->getAlias() . '.' . $field;
    }
}