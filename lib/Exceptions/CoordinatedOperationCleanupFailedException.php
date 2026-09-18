<?php

namespace PHPNomad\Database\Exceptions;

use RuntimeException;
use Throwable;

/** An operation failed and its cleanup could not establish a safe outcome. */
class CoordinatedOperationCleanupFailedException extends CoordinatedOperationOutcomeUnknownException
{
    /** Retain the cleanup failure as previous and the earlier operation failure separately. */
    public function __construct(Throwable $operationFailure, Throwable $cleanupFailure)
    {
        parent::__construct('The coordinated operation failed and cleanup could not be confirmed.');
    }

    /** Return the exact earlier failure that required cleanup. */
    public function getOperationFailure(): Throwable
    {
        return new RuntimeException();
    }
}
