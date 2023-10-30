<?php

namespace PHPNomad\Database\Events;

use PHPNomad\Database\Interfaces\Table;
use PHPNomad\Events\Interfaces\Event;

class RecordUpdated implements Event
{
    protected Table $table;
    protected array $data;
    protected int $dataId;

    public function __construct(Table $table, array $data, int $dataId)
    {
        $this->table = $table;
        $this->data = $data;
        $this->dataId = $dataId;
    }

    /**
     * Gets the table this record was created in.
     *
     * @return Table
     */
    public function getTable(): Table
    {
        return $this->table;
    }

    /**
     * Gets the data used to store the record in the database.
     *
     * @return array
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * Gets the record ID
     *
     * @return int
     */
    public function getDataId(): int
    {
        return $this->dataId;
    }

    public static function getId(): string
    {
        return 'record_created';
    }
}