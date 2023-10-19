<?php

namespace Phoenix\Database\Factories\Columns;

use Phoenix\Database\Factories\Column;
use Phoenix\Database\Interfaces\CanConvertToColumn;

class PrimaryKeyFactory implements CanConvertToColumn
{
    public function toColumn(): Column
    {
        return new Column('id','BIGINT',null,'AUTO_INCREMENT','PRIMARY KEY');
    }
}