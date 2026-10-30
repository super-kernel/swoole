<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use Closure;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Swoole\Constants\SupervisorSignal;
use SuperKernel\Swoole\Exception\SupervisorException;
use Swoole\Process;
use Throwable;

use function is_int;
use function pcntl_signal;
use function pcntl_sigprocmask;

use const SIG_BLOCK;
use const SIG_DFL;
use const SIG_SETMASK;
use const SIG_UNBLOCK;

/** @internal Private child processes and signal state for one MainProcess; never shared between runs. */
final class ChildProcessGroup
{
    /** @var list<int> */
    private array $signalMask = [];
    private readonly SupervisorState $state;
    private readonly ChildShutdown $shutdown;
    private readonly PendingSignals $pendingSignals;
    private readonly ChildWaiter $waiter;

    public function __construct(private readonly StdoutLoggerInterface $logger)
    {
        $this->state = new SupervisorState();
        $this->shutdown = new ChildShutdown($this->state);
        $reaper = new ChildReaper($this->state, $this->shutdown);
        $this->pendingSignals = new PendingSignals($this->state, $this->shutdown, $reaper);
        $this->waiter = new ChildWaiter($this->state, $this->shutdown, $reaper, $this->pendingSignals);
    }

    /** @throws SupervisorException if supervisor signals cannot be blocked before startup */
    public function prepare(): void
    {
        if (!pcntl_sigprocmask(SIG_BLOCK, SupervisorSignal::numbers(), $this->signalMask)) {
            throw new SupervisorException('Unable to block supervisor signals before startup.');
        }
        $this->state->signalsBlocked = true;
    }

    /**
     * Forks a child after preparation; only the child's inherited PID-file handle is closed.
     *
     * @param Closure(): mixed $callback
     *
     * @throws SupervisorException if starting the child does not return a valid PID
     * @throws Throwable           if native child-process construction fails
     */
    public function spawn(Closure $callback, ?PidFile $pidFile): void
    {
        $signalMask = $this->signalMask;
        $logger = $this->logger;
        $child = new Process(static function (Process $process) use ($callback, $pidFile, $signalMask, $logger): void {
            try {
                $pidFile?->close();
                foreach (SupervisorSignal::numbers() as $signal) {
                    pcntl_signal($signal, SIG_DFL);
                }
                if (!pcntl_sigprocmask(SIG_SETMASK, $signalMask)
                    || !pcntl_sigprocmask(SIG_UNBLOCK, SupervisorSignal::numbers())) {
                    throw new SupervisorException('Unable to restore child signal delivery.');
                }
                $callback();
            } catch (Throwable $error) {
                $logger->error('Mounted process failed: ' . $error->getMessage());
                $process->exit(1);
            }
        }, false, Process::PIPE_TYPE_NONE, false);
        $pid = $child->start();
        if (!is_int($pid) || 0 >= $pid) {
            throw new SupervisorException('Unable to start a mounted child process.');
        }
        $this->state->children[$pid] = $child;
    }

    /**
     * Drains pending signals and child exits.
     *
     * @return bool Whether another initialization callback may run
     */
    public function poll(): bool
    {
        return $this->pendingSignals->drain();
    }

    /** @throws SupervisorException after child failure or when waiting for supervisor signals fails */
    public function finish(): void
    {
        $this->waiter->wait();
        if (null !== $this->state->failure) {
            throw $this->state->failure;
        }
    }

    /**
     * Stops and reaps children without replacing an existing initialization exception with a child failure.
     *
     * @throws SupervisorException if waiting for supervisor signals fails
     */
    public function abort(): void
    {
        $this->shutdown->request();
        $this->waiter->wait();
    }

    /** Drains pending signals and restores the original signal mask after supervisor resource cleanup. */
    public function restore(): void
    {
        if (!$this->state->signalsBlocked) {
            return;
        }
        try {
            $this->pendingSignals->drain();
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $this->signalMask);
            $this->state->signalsBlocked = false;
        }
    }
}
