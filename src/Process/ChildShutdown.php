<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use SuperKernel\Swoole\Exception\SupervisorException;
use Swoole\Process;

use const SIGTERM;

/**
 * Sends SIGTERM once to every live direct child in mounting order and records an undeliverable signal as a failure.
 *
 * @internal
 */
final readonly class ChildShutdown
{
    public function __construct(private SupervisorState $state)
    {
    }

    public function request(): void
    {
        if ($this->state->stopping) {
            return;
        }
        $this->state->stopping = true;
        foreach ($this->state->children as $pid => $child) {
            if (!@Process::kill($pid, SIGTERM) && Process::kill($pid, 0)) {
                $this->state->failure ??= new SupervisorException('Unable to signal a mounted child process.');
            }
        }
    }
}
