<?php

namespace Phoenix\Database\Factories\Columns;

use Phoenix\Database\Factories\Column;
use Phoenix\Database\Interfaces\CanConvertToColumn;

class DateCreatedFactory implements CanConvertToColumn
{
    public function toColumn(): Column
    {
        return new Column('dateCreated,'TIMESTAMP', null, 'NOT NULL');
    }
}