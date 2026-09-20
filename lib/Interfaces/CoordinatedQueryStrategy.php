<?php

namespace PHPNomad\Database\Interfaces;

use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Datastore\Exceptions\RecordNotFoundException;

/** Optional atomic, coordinated execution on one database resource. */
interface CoordinatedQueryStrategy extends QueryStrategy
{
    /**
     * Run one attempt with an operation-local query strategy.
     *
     * All declared participant writes commit together. Successful operations
     * coordinating the same record are equivalent to some serial execution.
     * The logical scope retains the resource, table, and complete identity.
     * A tenant-blind application guard must not replace that scope. Inherent
     * backend contention may still serialize otherwise unrelated operations.
     * This guarantee covers callers using this capability, not bypass writers.
     *
     * The identity must identify one existing coordination record. Its table
     * must occur in participants. Identifiers and table metadata must remain
     * stable for this call. Integrations validate the actual resource support.
     *
     * The callback receives a fresh-read, same-resource QueryStrategy that is
     * valid only during the callback. It must not call the outer strategy,
     * emit events, mutate shared caches, or perform external side effects.
     * The strategy never replays the callback. Unsupported requests fail before
     * the callback or any write. Runtime conflicts may occur during execution.
     * Return means commit was confirmed. External publication is not included.
     *
     * @template TResult
     * @param non-empty-array<string, int|string> $identity
     * @param non-empty-list<Table> $participants
     * @param callable(QueryStrategy): TResult $operation
     * @return TResult
     * @throws \InvalidArgumentException Invalid or incomplete scope.
     * @throws UnsupportedCoordinationException Unsupported resource or ambient operation.
     * @throws RecordNotFoundException The coordination record is absent.
     * @throws CoordinatedOperationConflictException A conflict was rolled back.
     * @throws CoordinatedOperationOutcomeUnknownException Commit or rollback is uncertain.
     * @throws \Throwable Callback errors propagate after confirmed rollback.
     */
    public function coordinate(
        Table $coordinationTable,
        array $identity,
        array $participants,
        callable $operation
    );
}
