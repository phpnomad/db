<?php

namespace PHPNomad\Database\Exceptions;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;

/** The integration cannot establish whether all changes committed or rolled back. */
class CoordinatedOperationOutcomeUnknownException extends DatastoreErrorException
{
}
