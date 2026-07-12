<?php

namespace PHPNomad\Database\Tests\Doubles;

use PHPNomad\Events\Interfaces\Event;
use RuntimeException;

/**
 * Recording event strategy whose FIRST broadcast throws (after recording),
 * for proving per-event listener isolation in bulk emissions.
 */
class ThrowingOnceEventStrategy extends RecordingEventStrategy
{
    private bool $thrown = false;

    public function broadcast(Event $event): void
    {
        parent::broadcast($event);

        if (!$this->thrown) {
            $this->thrown = true;

            throw new RuntimeException('Simulated listener failure.');
        }
    }
}
