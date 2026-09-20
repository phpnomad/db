<?php

namespace PHPNomad\Database\Exceptions;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use RuntimeException;
use Throwable;

/** Reporting the classified operation failure also failed. */
class CoordinatedOperationReportingFailedException extends DatastoreErrorException
{
    public function __construct(Throwable $operationFailure, Throwable $reportingFailure)
    {
        parent::__construct('');
    }

    /** Return the already-classified coordinated operation failure. */
    public function getOperationFailure(): Throwable
    {
        return new RuntimeException();
    }

    /** Return the failure raised while reporting the operation failure. */
    public function getReportingFailure(): Throwable
    {
        return new RuntimeException();
    }
}
