<?php

namespace PHPNomad\Database\Factories\Columns;

use DateTimeImmutable;
use PHPNomad\Database\Factories\Column;
use PHPNomad\Database\Interfaces\CanConvertToColumn;

class DateModifiedFactory implements CanConvertToColumn
{
    public function toColumn(): Column
    {
        return (new Column('dateModified', 'TIMESTAMP', null, 'NOT NULL DEFAULT CURRENT_TIMESTAMP'))
            ->withPhpDefault(static fn (): string => (new DateTimeImmutable())->format('Y-m-d H:i:s'));
    }
}
