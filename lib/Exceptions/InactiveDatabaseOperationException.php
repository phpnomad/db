<?php

namespace PHPNomad\Database\Exceptions;

/** An operation-local query strategy was used outside its callback lifetime. */
class InactiveDatabaseOperationException extends \LogicException
{
}
