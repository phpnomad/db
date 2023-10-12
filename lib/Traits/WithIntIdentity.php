<?php

namespace Phoenix\Database\Traits;

trait WithIntIdentity
{
    protected int $id;

    /**
     * Gets the item's ID
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }
}