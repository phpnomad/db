<?php

namespace PHPNomad\Database\Factories\Columns;

use PHPNomad\Chrono\Interfaces\ClockStrategy;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\CanConvertToColumn;

class DateCreatedFactory implements CanConvertToColumn
{
    public function __construct(protected ClockStrategy $clock)
    {
    }

    public function toColumn(): Column
    {
        return (new Column('dateCreated', 'TIMESTAMP', null, 'NOT NULL DEFAULT CURRENT_TIMESTAMP'))
            ->withPhpDefault(fn (): string => $this->clock->now()->format('Y-m-d H:i:s'));
    }
}
