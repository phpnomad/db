<?php

namespace Phoenix\Database\Interfaces;

interface HasGlobalDatabasePrefix
{
    /**
     * Gets the database prefix.
     * @return string
     */
    public function getGlobalDatabasePrefix(): string;
}