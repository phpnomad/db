<?php

namespace Phoenix\Database\Services;

use DateTime;
use Phoenix\Database\Interfaces\CanConvertDatabaseStringToDateTime;
use Phoenix\Database\Interfaces\CanConvertToDatabaseDateString;

class DateFormatterService
{

    protected CanConvertToDatabaseDateString $databaseDateToDateStringAdapter;
    protected CanConvertDatabaseStringToDateTime $databaseStringToDateAdapter;

    public function __construct(CanConvertToDatabaseDateString $databaseDateToDateStringAdapter, CanConvertDatabaseStringToDateTime $databaseStringToDateAdapter)
    {
        $this->databaseDateToDateStringAdapter = $databaseDateToDateStringAdapter;
        $this->databaseStringToDateAdapter = $databaseStringToDateAdapter;
    }

    /**
     * Returns the date string if it is set, otherwise returns null.
     *
     * @param DateTime|null $dateTime
     * @return string|null
     */
    public function getDateStringOrNull(?DateTime $dateTime): ?string
    {
        if(is_null($dateTime)){
            return null;
        }

        return $this->databaseDateToDateStringAdapter->toDatabaseDateString($dateTime);
    }

    /**
     * Creates a DateTime object from the provided string, otherwise sets null.
     *
     * @param string|null $date
     * @return DateTime|null
     */
    public function getDateTimeOrNull(?string $date): ?DateTime
    {
        if(is_null($date)){
            return null;
        }

        return $this->databaseStringToDateAdapter->toDateTime($date);
    }
}