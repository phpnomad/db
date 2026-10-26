<?php

namespace PHPNomad\Database\Exceptions;

use PHPNomad\Datastore\Exceptions\DatastoreErrorException;

/** The active database adapter cannot safely retire named columns. */
class UnsupportedColumnRetirementException extends DatastoreErrorException
{
}
