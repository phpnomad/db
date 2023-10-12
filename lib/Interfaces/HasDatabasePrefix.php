<?php

namespace Phoenix\Database\Interfaces;

interface HasDatabasePrefix
{
    /**
     * Gets the database prefix.
     * @return string
     */
    public function getDatabasePrefix(): string;
}