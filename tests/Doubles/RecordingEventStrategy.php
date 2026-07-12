<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Events\Interfaces\Event;
use PHPNomad\Events\Interfaces\EventStrategy;

/**
 * Event strategy that records every broadcast, for tests that assert on the
 * event contract (which events fired, with which payloads).
 */
class RecordingEventStrategy implements EventStrategy
{
    /** @var Event[] */
    public array $broadcasts = [];

    public function broadcast(Event $event): void
    {
        $this->broadcasts[] = $event;
    }

    /**
     * All recorded broadcasts of one event class, in broadcast order.
     *
     * @template T of Event
     * @param class-string<T> $eventClass
     * @return array<int, T>
     */
    public function ofType(string $eventClass): array
    {
        return array_values(array_filter($this->broadcasts, fn (Event $event) => $event instanceof $eventClass));
    }

    public function attach(string $event, callable $action, ?int $priority = null): void
    {
    }

    public function detach(string $event, callable $action, ?int $priority = null): void
    {
    }
}
