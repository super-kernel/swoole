<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use SuperKernel\Swoole\Constants\SupervisorSignal;

use function array_shift;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_signal_get_handler;
use function pcntl_sigprocmask;
use function pcntl_sigtimedwait;
use function pcntl_sigwaitinfo;
use function usleep;

use const SIG_BLOCK;
use const SIG_UNBLOCK;

/**
 * Receives the blocked supervisor signals one at a time.
 *
 * Where PHP cannot wait for a blocked signal, such as on macOS, handlers record the signals during a short unblocked
 * window, and waiting polls for them.
 *
 * @internal
 */
final class SupervisorSignalReceiver
{
    private const int POLL_MICROSECONDS = 10_000;

    /** @var array<int, mixed> The handlers that the recording handlers replaced, by signal */
    private array $handlers = [];

    /** @var list<int> The signals that handlers recorded and nothing has received yet */
    private array $recorded = [];

    /** @param bool $canWait Whether PHP has pcntl_sigwaitinfo() and pcntl_sigtimedwait() */
    public function __construct(private readonly bool $canWait)
    {
    }

    public function install(): void
    {
        if ($this->canWait) {
            return;
        }

        foreach (SupervisorSignal::numbers() as $signal) {
            $this->handlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, function (int $signal): void {
                $this->recorded[] = $signal;
            });
        }
    }

    public function uninstall(): void
    {
        foreach ($this->handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }

        $this->handlers = [];
    }

    /** @return int|false The next pending signal, or false when none is pending */
    public function poll(): int|false
    {
        if ($this->canWait) {
            $info = [];

            return pcntl_sigtimedwait(SupervisorSignal::numbers(), $info, 0, 1);
        }

        if ([] === $this->recorded) {
            pcntl_sigprocmask(SIG_UNBLOCK, SupervisorSignal::numbers());
            pcntl_signal_dispatch();
            pcntl_sigprocmask(SIG_BLOCK, SupervisorSignal::numbers());
        }

        return array_shift($this->recorded) ?? false;
    }

    /** @return int|false The next signal once one is pending, or false when native waiting fails */
    public function wait(): int|false
    {
        if ($this->canWait) {
            return pcntl_sigwaitinfo(SupervisorSignal::numbers());
        }

        while (false === ($signal = $this->poll())) {
            usleep(self::POLL_MICROSECONDS);
        }

        return $signal;
    }
}
