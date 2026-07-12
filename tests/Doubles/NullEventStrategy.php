<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Events\Interfaces\Event;
use PHPNomad\Events\Interfaces\EventStrategy;

/**
 * Event strategy that swallows everything, for tests that don't assert on
 * broadcasts.
 */
class NullEventStrategy implements EventStrategy
{
    public function broadcast(Event $event): void
    {
    }

    public function attach(string $event, callable $action, ?int $priority = null): void
    {
    }

    public function detach(string $event, callable $action, ?int $priority = null): void
    {
    }
}
