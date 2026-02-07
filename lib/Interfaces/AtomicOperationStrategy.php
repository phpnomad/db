<?php

namespace PHPNomad\Database\Interfaces;

interface AtomicOperationStrategy
{
    /**
     * Execute a callable within an atomic operation boundary.
     * If the callable throws, the operation is rolled back.
     * If it succeeds, the operation is committed.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function atomic(callable $operation);
}
