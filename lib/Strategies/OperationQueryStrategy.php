<?php

namespace PHPNomad\Database\Strategies;

use InvalidArgumentException;
use PHPNomad\Database\Exceptions\InactiveDatabaseOperationException;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use PHPNomad\Database\Interfaces\HasQueryTables;
use PHPNomad\Database\Interfaces\QueryBuilder;
use PHPNomad\Database\Interfaces\QueryStrategy;
use PHPNomad\Database\Interfaces\Table;

final class OperationQueryStrategy implements QueryStrategy
{
    private QueryStrategy $delegate;

    /** @var list<string> */
    private array $participantNames;

    private bool $closed = false;

    /** @param non-empty-list<Table> $participants */
    public function __construct(QueryStrategy $delegate, array $participants)
    {
        /** @var array<array-key, mixed> $candidateParticipants */
        $candidateParticipants = $participants;
        if (
            $candidateParticipants === []
            || array_keys($candidateParticipants) !== range(0, count($candidateParticipants) - 1)
        ) {
            throw new InvalidArgumentException('Participants must be a nonempty list of tables.');
        }

        $participantNames = [];
        foreach ($candidateParticipants as $participant) {
            if (!$participant instanceof Table) {
                throw new InvalidArgumentException('Every participant must be a table.');
            }

            $name = $participant->getName();
            if ($name === '') {
                throw new InvalidArgumentException('Participant table names must not be empty.');
            }

            $participantNames[] = $name;
        }

        $this->delegate = $delegate;
        $this->participantNames = $participantNames;
    }

    /**
     * Invalidate this handle permanently. Repeated closure is harmless.
     * This never commits, rolls back, or closes the delegate's connection.
     */
    public function close(): void
    {
        $this->closed = true;
    }

    /** @return array<array-key, mixed> */
    public function query(QueryBuilder $builder): array
    {
        if ($this->closed) {
            throw new InactiveDatabaseOperationException('The database operation is closed.');
        }

        if (!$builder instanceof HasQueryTables) {
            throw new UnsupportedCoordinationException('Query builders must report their referenced tables.');
        }

        $sources = $builder->getReferencedTables();
        if ($sources === [] || array_keys($sources) !== range(0, count($sources) - 1)) {
            throw new InvalidArgumentException('Query sources must be a nonempty list of tables.');
        }

        foreach ($sources as $source) {
            if (!$source instanceof Table) {
                throw new InvalidArgumentException('Every query source must be a table.');
            }

            $name = $source->getName();
            if ($name === '' || !in_array($name, $this->participantNames, true)) {
                throw new InvalidArgumentException('Every query source must be a declared table.');
            }
        }

        return $this->delegate->query($builder);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int>
     */
    public function insert(Table $table, array $data): array
    {
        if ($this->closed) {
            throw new InactiveDatabaseOperationException('The database operation is closed.');
        }

        $name = $table->getName();
        if ($name === '' || !in_array($name, $this->participantNames, true)) {
            throw new InvalidArgumentException('The table must be a declared participant.');
        }

        return $this->delegate->insert($table, $data);
    }

    /** @param array<string, int> $ids */
    public function delete(Table $table, array $ids): void
    {
        if ($this->closed) {
            throw new InactiveDatabaseOperationException('The database operation is closed.');
        }

        $name = $table->getName();
        if ($name === '' || !in_array($name, $this->participantNames, true)) {
            throw new InvalidArgumentException('The table must be a declared participant.');
        }

        $this->delegate->delete($table, $ids);
    }

    /**
     * @param array<string, int> $ids
     * @param array<string, mixed> $data
     */
    public function update(Table $table, array $ids, array $data): void
    {
        if ($this->closed) {
            throw new InactiveDatabaseOperationException('The database operation is closed.');
        }

        $name = $table->getName();
        if ($name === '' || !in_array($name, $this->participantNames, true)) {
            throw new InvalidArgumentException('The table must be a declared participant.');
        }

        $this->delegate->update($table, $ids, $data);
    }

    public function estimatedCount(Table $table): int
    {
        if ($this->closed) {
            throw new InactiveDatabaseOperationException('The database operation is closed.');
        }

        $name = $table->getName();
        if ($name === '' || !in_array($name, $this->participantNames, true)) {
            throw new InvalidArgumentException('The table must be a declared participant.');
        }

        return $this->delegate->estimatedCount($table);
    }
}
