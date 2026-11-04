<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use const SIGINT;
use const SIGTERM;

/**
 * Drains pending supervisor signals without blocking, requesting shutdown on termination and reaping exited children.
 *
 * @internal
 */
final readonly class PendingSignals
{
    public function __construct(
        private SupervisorState          $state,
        private ChildShutdown            $shutdown,
        private ChildReaper              $reaper,
        private SupervisorSignalReceiver $signals,
    )
    {
    }

    /** @return bool Whether another initialization callback may run */
    public function drain(): bool
    {
        if (!$this->state->signalsBlocked) {
            return !$this->state->stopping;
        }
        while (true) {
            $signal = $this->signals->poll();
            if (false === $signal) {
                return !$this->state->stopping;
            }
            if (SIGTERM === $signal || SIGINT === $signal) {
                $this->shutdown->request();
            }
            $this->reaper->reap();
        }
    }
}
