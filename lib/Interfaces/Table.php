<?php

namespace Phoenix\Database\Interfaces;
interface Table
{
    /**
     * Gets the name of this table.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Gets the alias for this table.
     *
     * @return string
     */
    public function getAlias(): string;
}