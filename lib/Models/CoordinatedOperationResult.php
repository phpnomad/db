<?php

namespace PHPNomad\Database\Models;

use Throwable;

/** The callback value and post-commit publication failures. */
class CoordinatedOperationResult
{
    private mixed $value;

    /** @var list<Throwable> */
    private array $publicationFailures;

    /** @param list<Throwable> $publicationFailures */
    public function __construct(mixed $value, array $publicationFailures = [])
    {
        $this->value = $value;
        $this->publicationFailures = $publicationFailures;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    /** @return list<Throwable> */
    public function getPublicationFailures(): array
    {
        return $this->publicationFailures;
    }

    public function hasPublicationFailures(): bool
    {
        return $this->publicationFailures !== [];
    }
}
