<?php

namespace PHPNomad\Database\Events;

use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Events\Interfaces\Event;

class RecordDeleted implements Event
{

    protected Table $table;
    protected int $dataId;

    public function __construct(Table $table, int $dataId)
    {
        $this->table = $table;
        $this->dataId = $dataId;
    }

    /**
     * Gets the record ID that was deleted.
     *
     * @return int
     */
    public function getDataId(): int
    {
        return $this->dataId;
    }

    /**
     * Gets the table this record was deleted from.
     *
     * @return \PHPNomad\Database\Interfaces\Table
     */
    public function getTable(): Table
    {
        return $this->table;
    }

    public static function getId(): string
    {
        return 'record_deleted';
    }
}