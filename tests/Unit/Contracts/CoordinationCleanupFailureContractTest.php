<?php

namespace PHPNomad\Database\Tests\Unit\Contracts;

use Error;
use PHPNomad\Database\Exceptions\CoordinatedOperationCleanupFailedException;
use PHPNomad\Database\Exceptions\CoordinatedOperationConflictException;
use PHPNomad\Database\Exceptions\CoordinatedOperationOutcomeUnknownException;
use PHPNomad\Datastore\Exceptions\DatastoreErrorException;
use PHPNomad\Database\Tests\TestCase;
use RuntimeException;

/** Keeps both causes without depending on a storage adapter. Each adapter tests its cleanup behavior. */
final class CoordinationCleanupFailureContractTest extends TestCase
{
    /** @dataProvider causeKinds */
    public function testBothOriginalFailuresRemainInspectableAsAnUnknownOutcome(bool $operationIsError, bool $cleanupIsError): void
    {
        $operation = $operationIsError ? new Error('Private operation detail') : new RuntimeException('Private operation detail');
        $cleanup = $cleanupIsError ? new Error('Private cleanup detail') : new RuntimeException('Private cleanup detail');
        $failure = new CoordinatedOperationCleanupFailedException($operation, $cleanup);

        self::assertInstanceOf(CoordinatedOperationOutcomeUnknownException::class, $failure);
        self::assertInstanceOf(DatastoreErrorException::class, $failure);
        self::assertNotInstanceOf(CoordinatedOperationConflictException::class, $failure);
        self::assertSame($operation, $failure->getOperationFailure());
        self::assertSame($cleanup, $failure->getPrevious());
        self::assertSame('The coordinated operation failed and cleanup could not be confirmed.', $failure->getMessage());
        self::assertSame(0, $failure->getCode());
        self::assertNull($operation->getPrevious());
        self::assertNull($cleanup->getPrevious());

        $otherOperation = new RuntimeException('Another operation');
        $otherCleanup = new RuntimeException('Another cleanup');
        $other = new CoordinatedOperationCleanupFailedException($otherOperation, $otherCleanup);
        self::assertSame($otherOperation, $other->getOperationFailure());
        self::assertSame($otherCleanup, $other->getPrevious());
        self::assertSame($operation, $failure->getOperationFailure());
        self::assertSame($cleanup, $failure->getPrevious());
    }

    /** @return array<string, array{bool, bool}> */
    public static function causeKinds(): array
    {
        return [
            'exceptions' => [false, false], 'operation error' => [true, false],
            'cleanup error' => [false, true], 'both errors' => [true, true],
        ];
    }
}
