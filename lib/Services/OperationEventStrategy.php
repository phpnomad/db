<?php

namespace PHPNomad\Database\Services;

use PHPNomad\Events\Interfaces\Event;
use PHPNomad\Events\Interfaces\EventStrategy;
use PHPNomad\Database\Exceptions\UnsupportedCoordinationException;
use Throwable;

/** Buffers datastore events until the owning operation has committed. */
class OperationEventStrategy implements EventStrategy
{
    private EventStrategy $sharedStrategy;

    /** @var list<Event> */
    private array $events = [];

    public function __construct(EventStrategy $sharedStrategy)
    {
        $this->sharedStrategy = $sharedStrategy;
    }

    public function broadcast(Event $event): void
    {
        $this->events[] = $event;
    }

    public function attach(string $event, callable $action, ?int $priority = null): void
    {
        throw new UnsupportedCoordinationException('Event bindings cannot change inside a coordinated operation.');
    }

    public function detach(string $event, callable $action, ?int $priority = null): void
    {
        throw new UnsupportedCoordinationException('Event bindings cannot change inside a coordinated operation.');
    }

    /** @return list<Throwable> */
    public function publish(): array
    {
        $failures = [];

        foreach ($this->events as $event) {
            try {
                $this->sharedStrategy->broadcast($event);
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        $this->events = [];

        return $failures;
    }

    public function discard(): void
    {
        $this->events = [];
    }
}
