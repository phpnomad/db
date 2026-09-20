<?php

namespace PHPNomad\Database\Exceptions;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;

/** A concurrency conflict prevented the operation and rollback was confirmed. */
class CoordinatedOperationConflictException extends DatastoreErrorException
{
}
