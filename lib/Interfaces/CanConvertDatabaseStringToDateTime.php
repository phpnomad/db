<?php

namespace Phoenix\Database\Interfaces;

use DateTime;

interface CanConvertDatabaseStringToDateTime
{
    /**
     * Converts the specified string into a DateTime object.
     *
     * @param string $date
     * @return DateTime
     */
    public function toDateTime(string $date): DateTime;
}