<?php

namespace PHPNomad\Database\Exceptions;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use Throwable;

/** Reporting the classified operation failure also failed. */
class CoordinatedOperationReportingFailedException extends DatastoreErrorException
{
    private Throwable $operationFailure;

    private Throwable $reportingFailure;

    public function __construct(Throwable $operationFailure, Throwable $reportingFailure)
    {
        $this->operationFailure = $operationFailure;
        $this->reportingFailure = $reportingFailure;

        parent::__construct(
            'The coordinated operation failure could not be reported.',
            0,
            $reportingFailure
        );
    }

    /** Return the already-classified coordinated operation failure. */
    public function getOperationFailure(): Throwable
    {
        return $this->operationFailure;
    }

    /** Return the failure raised while reporting the operation failure. */
    public function getReportingFailure(): Throwable
    {
        return $this->reportingFailure;
    }
}
