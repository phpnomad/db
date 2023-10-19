<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Factories\Index;

interface CanConvertToIndex
{
    public function toIndex(): Index;
}