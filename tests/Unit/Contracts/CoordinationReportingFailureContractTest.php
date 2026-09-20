<?php

namespace PHPNomad\Database\Tests\Unit\Contracts;

use Error;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Database\Exceptions\CoordinatedOperationReportingFailedException;
use PHPNomad\Database\Tests\TestCase;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use RuntimeException;

/** Keeps reporting failure separate from the database outcome classification. */
final class CoordinationReportingFailureContractTest extends TestCase
{
    public function testReportingFailureUsesTheNeutralDatastoreClassification(): void
    {
        $operation = new RuntimeException('Private operation detail');
        $reporting = new RuntimeException('Private reporting detail');
        $failure = new CoordinatedOperationReportingFailedException($operation, $reporting);

        self::assertInstanceOf(DatastoreErrorException::class, $failure);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
        self::assertNotInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $failure);
    }

    /** @dataProvider causeKinds */
    public function testBothExactFailuresRemainInspectable(bool $operationIsError, bool $reportingIsError): void
    {
        $operation = $operationIsError
            ? new Error('Private operation detail')
            : new RuntimeException('Private operation detail');
        $reporting = $reportingIsError
            ? new Error('Private reporting detail')
            : new RuntimeException('Private reporting detail');
        $failure = new CoordinatedOperationReportingFailedException($operation, $reporting);

        self::assertSame($operation, $failure->getOperationFailure());
        self::assertSame($reporting, $failure->getReportingFailure());
        self::assertSame($reporting, $failure->getPrevious());
        self::assertSame('The coordinated operation failure could not be reported.', $failure->getMessage());
        self::assertSame(0, $failure->getCode());
    }

    public function testCleanupCompositeRemainsTheExactOperationFailure(): void
    {
        $operation = new RuntimeException('Private operation detail');
        $cleanup = new Error('Private cleanup detail');
        $cleanupFailure = new CoordinatedOperationCleanupFailedException($operation, $cleanup);
        $reporting = new RuntimeException('Private reporting detail');
        $failure = new CoordinatedOperationReportingFailedException($cleanupFailure, $reporting);

        /** @var CoordinatedOperationCleanupFailedException $retained */
        $retained = $failure->getOperationFailure();
        self::assertSame($cleanupFailure, $retained);
        self::assertSame($operation, $retained->getOperationFailure());
        self::assertSame($cleanup, $retained->getPrevious());
        self::assertSame($reporting, $failure->getReportingFailure());
        self::assertSame($reporting, $failure->getPrevious());
        self::assertSame('The coordinated operation failure could not be reported.', $failure->getMessage());
        self::assertSame(0, $failure->getCode());
    }

    /** @dataProvider retryRelevantOperationKinds */
    public function testRetryRelevantOperationFailureRemainsControlling(bool $outcomeUnknown): void
    {
        $operation = $outcomeUnknown
            ? new CoordinatedOperationOutcomeUnknownException('Private operation detail')
            : new CoordinatedOperationConflictException('Private operation detail');
        $reporting = new RuntimeException('Private reporting detail');
        $failure = new CoordinatedOperationReportingFailedException($operation, $reporting);

        self::assertInstanceOf(DatastoreErrorException::class, $failure);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
        self::assertNotInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $failure);
        self::assertSame($operation, $failure->getOperationFailure());
        self::assertSame($reporting, $failure->getReportingFailure());
        self::assertSame($reporting, $failure->getPrevious());
        self::assertSame('The coordinated operation failure could not be reported.', $failure->getMessage());
        self::assertSame(0, $failure->getCode());
    }

    public function testEachEnvelopeOwnsItsOperationFailure(): void
    {
        $firstOperation = new RuntimeException('First operation');
        $first = new CoordinatedOperationReportingFailedException($firstOperation, new RuntimeException('First reporting'));
        $secondOperation = new RuntimeException('Second operation');
        $second = new CoordinatedOperationReportingFailedException($secondOperation, new RuntimeException('Second reporting'));

        self::assertSame($firstOperation, $first->getOperationFailure());
        self::assertSame($secondOperation, $second->getOperationFailure());
    }

    public function testEachEnvelopeOwnsItsReportingFailure(): void
    {
        $firstReporting = new RuntimeException('First reporting');
        $first = new CoordinatedOperationReportingFailedException(new RuntimeException('First operation'), $firstReporting);
        $secondReporting = new RuntimeException('Second reporting');
        $second = new CoordinatedOperationReportingFailedException(new RuntimeException('Second operation'), $secondReporting);

        self::assertSame($firstReporting, $first->getReportingFailure());
        self::assertSame($secondReporting, $second->getReportingFailure());
    }

    public function testEachEnvelopeOwnsItsPreviousCause(): void
    {
        $firstReporting = new RuntimeException('First reporting');
        $first = new CoordinatedOperationReportingFailedException(new RuntimeException('First operation'), $firstReporting);
        $secondReporting = new RuntimeException('Second reporting');
        $second = new CoordinatedOperationReportingFailedException(new RuntimeException('Second operation'), $secondReporting);

        self::assertSame($firstReporting, $first->getPrevious());
        self::assertSame($secondReporting, $second->getPrevious());
    }

    /** @return array<string, array{bool, bool}> */
    public static function causeKinds(): array
    {
        return [
            'exceptions' => [false, false], 'operation error' => [true, false],
            'reporting error' => [false, true], 'both errors' => [true, true],
        ];
    }

    /** @return array<string, array{bool}> */
    public static function retryRelevantOperationKinds(): array
    {
        return ['conflict' => [false], 'outcome unknown' => [true]];
    }
}
