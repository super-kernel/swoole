<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use Closure;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Swoole\Exception\SupervisorException;
use Swoole\Process;
use Throwable;

use function array_keys;
use function in_array;
use function is_int;
use function pcntl_get_last_error;
use function pcntl_signal;
use function pcntl_sigprocmask;
use function pcntl_sigtimedwait;
use function pcntl_sigwaitinfo;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function pcntl_wifexited;
use function pcntl_wifsignaled;
use function pcntl_wtermsig;
use function sprintf;

use const PCNTL_EINTR;
use const SIG_BLOCK;
use const SIG_DFL;
use const SIG_SETMASK;
use const SIG_UNBLOCK;
use const SIGCHLD;
use const SIGINT;
use const SIGTERM;
use const WNOHANG;

/** @internal Private child processes and signal state for one MainProcess; never shared between runs. */
final class ChildProcessGroup
{
    private const array SIGNALS = [
        SIGTERM,
        SIGINT,
        SIGCHLD,
    ];

    /** @var array<int, Process> Live direct children, in mounting order. */
    private array $children = [];
    /** @var list<int> */
    private array $signalMask = [];
    private ?SupervisorException $failure = null;
    private bool $stopping = false;
    private bool $signalsBlocked = false;

    public function __construct(private readonly StdoutLoggerInterface $logger)
    {
    }

    /** @throws SupervisorException if supervisor signals cannot be blocked before startup */
    public function prepare(): void
    {
        if (!pcntl_sigprocmask(SIG_BLOCK, self::SIGNALS, $this->signalMask)) {
            throw new SupervisorException('Unable to block supervisor signals before startup.');
        }
        $this->signalsBlocked = true;
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
                foreach (self::SIGNALS as $signal) {
                    pcntl_signal($signal, SIG_DFL);
                }
                if (!pcntl_sigprocmask(SIG_SETMASK, $signalMask)
                    || !pcntl_sigprocmask(SIG_UNBLOCK, self::SIGNALS)) {
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
        $this->children[$pid] = $child;
    }

    /**
     * Drains pending signals and child exits.
     *
     * @return bool Whether another initialization callback may run
     */
    public function poll(): bool
    {
        if (!$this->signalsBlocked) {
            return !$this->stopping;
        }
        while (true) {
            $info = [];
            $signal = pcntl_sigtimedwait(self::SIGNALS, $info, 0, 1);
            if (false === $signal) {
                return !$this->stopping;
            }
            if (SIGTERM === $signal || SIGINT === $signal) {
                $this->shutdownChildren();
            }
            $this->reapChildren();
        }
    }

    /** @throws SupervisorException after child failure or when waiting for supervisor signals fails */
    public function finish(): void
    {
        $this->waitForChildren();
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }

    /**
     * Stops and reaps children without replacing an existing initialization exception with a child failure.
     *
     * @throws SupervisorException if waiting for supervisor signals fails
     */
    public function abort(): void
    {
        $this->shutdownChildren();
        $this->waitForChildren();
    }

    /** Drains pending signals and restores the original signal mask after supervisor resource cleanup. */
    public function restore(): void
    {
        if (!$this->signalsBlocked) {
            return;
        }
        try {
            $this->poll();
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $this->signalMask);
            $this->signalsBlocked = false;
        }
    }

    private function waitForChildren(): void
    {
        while ([] !== $this->children) {
            $this->poll();
            $this->reapChildren();
            if ([] === $this->children) {
                return;
            }
            $signal = pcntl_sigwaitinfo(self::SIGNALS);
            if (false === $signal) {
                if (PCNTL_EINTR === pcntl_get_last_error()) {
                    continue;
                }
                throw new SupervisorException('Unable to wait for supervisor signals.');
            }
            if (SIGTERM === $signal || SIGINT === $signal) {
                $this->shutdownChildren();
            }
        }
    }

    private function reapChildren(): void
    {
        foreach (array_keys($this->children) as $pid) {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if (0 === $result || (-1 === $result && PCNTL_EINTR === pcntl_get_last_error())) {
                continue;
            }
            unset($this->children[$pid]);
            if (-1 === $result) {
                $this->failure ??= new SupervisorException('A mounted child could not be reaped by its supervisor.');
            } elseif (pcntl_wifexited($status) && 0 === pcntl_wexitstatus($status)) {
                continue;
            } elseif ($this->stopping && pcntl_wifsignaled($status)
                && in_array(pcntl_wtermsig($status), [
                    SIGTERM,
                    SIGINT,
                ], true)) {
                continue;
            } else {
                $this->failure ??= new SupervisorException(sprintf(
                    'A mounted process failed with exit code %d and signal %d.',
                    pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 0,
                    pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : 0,
                ));
            }
            $this->shutdownChildren();
        }
    }

    private function shutdownChildren(): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        foreach ($this->children as $pid => $child) {
            if (!@Process::kill($pid, SIGTERM) && Process::kill($pid, 0)) {
                $this->failure ??= new SupervisorException('Unable to signal a mounted child process.');
            }
        }
    }
}
