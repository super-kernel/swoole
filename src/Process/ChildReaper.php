<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use SuperKernel\Swoole\Exception\SupervisorException;

use function array_keys;
use function in_array;
use function pcntl_get_last_error;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function sprintf;

use const PCNTL_EINTR;
use const SIGINT;
use const SIGTERM;
use const WNOHANG;

/**
 * Reaps exited direct children without blocking and stops the remaining children after the first failure.
 *
 * @internal
 */
final readonly class ChildReaper
{
    public function __construct(private SupervisorState $state, private ChildShutdown $shutdown)
    {
    }

    public function reap(): void
    {
        foreach (array_keys($this->state->children) as $pid) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if (0 === $result || (-1 === $result && PCNTL_EINTR === pcntl_get_last_error())) {
                continue;
            }
            unset($this->state->children[$pid]);
            if (-1 === $result) {
                $this->state->failure ??= new SupervisorException(
                    'A mounted child could not be reaped by its supervisor.',
                );
            } elseif (pcntl_wifexited($status) && 0 === pcntl_wexitstatus($status)) {
                continue;
            } elseif ($this->state->stopping && pcntl_wifsignaled($status)
                && in_array(pcntl_wtermsig($status), [
                    SIGTERM,
                    SIGINT,
                ], true)) {
                continue;
            } else {
                $this->state->failure ??= new SupervisorException(sprintf(
                    'A mounted process failed with exit code %d and signal %d.',
                    pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 0,
                    pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : 0,
                ));
            }
            $this->shutdown->request();
        }
    }
}
