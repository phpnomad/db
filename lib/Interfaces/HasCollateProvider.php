<?php

namespace Phoenix\Database\Interfaces;

interface HasCollateProvider
{
    /**
     * @return string
     */
    public function getCollation(): ?string;
}