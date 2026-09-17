<?php

namespace PHPNomad\Database\Exceptions;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;

/** The requested coordinated operation cannot start on these resources. */
class UnsupportedCoordinationException extends DatastoreErrorException
{
}
