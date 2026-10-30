<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use SuperKernel\Swoole\Constants\SupervisorSignal;

use function pcntl_sigtimedwait;

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
        private SupervisorState $state,
        private ChildShutdown   $shutdown,
        private ChildReaper     $reaper,
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
            $info = [];
            $signal = pcntl_sigtimedwait(SupervisorSignal::numbers(), $info, 0, 1);
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
