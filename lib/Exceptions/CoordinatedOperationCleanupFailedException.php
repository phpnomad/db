<?php

namespace PHPNomad\Database\Exceptions;

use Throwable;

/** An operation failed and its cleanup could not establish a safe outcome. */
class CoordinatedOperationCleanupFailedException extends CoordinatedOperationOutcomeUnknownException
{
    private Throwable $operationFailure;

    /** Retain the cleanup failure as previous and the earlier operation failure separately. */
    public function __construct(Throwable $operationFailure, Throwable $cleanupFailure)
    {
        $this->operationFailure = $operationFailure;

        parent::__construct(
            'The coordinated operation failed and cleanup could not be confirmed.',
            0,
            $cleanupFailure
        );
    }

    /** Return the exact earlier failure that required cleanup. */
    public function getOperationFailure(): Throwable
    {
        return $this->operationFailure;
    }
}
