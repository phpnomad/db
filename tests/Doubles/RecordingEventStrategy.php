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

    public function attach(string $event, callable $action, ?int $priority = null): void
    {
    }

    public function detach(string $event, callable $action, ?int $priority = null): void
    {
    }
}
