<?php

namespace Phoenix\Database\Interfaces;

use Phoenix\Database\Factories\Column;

interface CanConvertToColumn
{
    public function toColumn(): Column;
}