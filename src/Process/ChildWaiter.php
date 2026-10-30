<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use SuperKernel\Swoole\Constants\SupervisorSignal;
use SuperKernel\Swoole\Exception\SupervisorException;

use function pcntl_get_last_error;
use function pcntl_sigwaitinfo;

use const PCNTL_EINTR;
use const SIGINT;
use const SIGTERM;

/**
 * Waits until every recorded direct child is reaped, requesting shutdown when a termination signal arrives.
 *
 * @internal
 */
final readonly class ChildWaiter
{
    public function __construct(
        private SupervisorState $state,
        private ChildShutdown   $shutdown,
        private ChildReaper     $reaper,
        private PendingSignals  $pendingSignals,
    )
    {
    }

    /** @throws SupervisorException if waiting for supervisor signals fails */
    public function wait(): void
    {
        while ([] !== $this->state->children) {
            $this->pendingSignals->drain();
            $this->reaper->reap();
            if ([] === $this->state->children) {
                return;
            }
            $signal = pcntl_sigwaitinfo(SupervisorSignal::numbers());
            if (false === $signal) {
                if (PCNTL_EINTR === pcntl_get_last_error()) {
                    continue;
                }
                throw new SupervisorException('Unable to wait for supervisor signals.');
            }
            if (SIGTERM === $signal || SIGINT === $signal) {
                $this->shutdown->request();
            }
        }
    }
}
