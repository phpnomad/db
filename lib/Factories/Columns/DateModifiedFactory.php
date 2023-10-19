<?php

namespace Phoenix\Database\Factories\Columns;

use Phoenix\Database\Factories\Column;
use Phoenix\Database\Interfaces\CanConvertToColumn;

class DateModifiedFactory implements CanConvertToColumn
{
    public function toColumn(): Column
    {
        return new Column('date_modified','TIMESTAMP', null, 'NOT NULL');
    }
}