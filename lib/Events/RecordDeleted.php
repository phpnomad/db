<?php

namespace Phoenix\Database\Events;

use Phoenix\Database\Interfaces\Table;
use Phoenix\Events\Interfaces\Event;

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
     * @return \Phoenix\Database\Interfaces\Table
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