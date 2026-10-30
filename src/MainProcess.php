<?php

declare(strict_types=1);

namespace SuperKernel\Swoole;

use Closure;
use LogicException;
use SplQueue;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Swoole\Config\SwooleConfigInterface;
use SuperKernel\Swoole\Exception\SupervisorException;
use SuperKernel\Swoole\Process\ChildProcessGroup;
use SuperKernel\Swoole\Process\PidFile;
use SuperKernel\Swoole\Runtime\RuntimeSettings;
use Swoole\Coroutine;
use Swoole\Process;
use Throwable;

use function fclose;
use function fopen;
use function getmypid;
use function is_resource;

use const PHP_SAPI;

/** Owns initialization, mounted child processes, daemonization and the supervisor PID file. */
final class MainProcess
{
    /** @var SplQueue<Closure(): mixed> */
    private readonly SplQueue $callbacks;
    private readonly ChildProcessGroup $children;
    private ?PidFile $pidFile = null;
    private int $owner;
    private bool $started = false;
    private bool $hasProcesses = false;

    public function __construct(
        private readonly StdoutLoggerInterface $logger,
        private readonly SwooleConfigInterface $config,
    )
    {
        $this->callbacks = new SplQueue();
        $this->children = new ChildProcessGroup($logger);
        $this->owner = getmypid();
    }

    /**
     * Mounts initialization in the supervisor, without executing it. Callbacks run once in FIFO order.
     *
     * @param callable(): mixed $callback Called without arguments; its return value is ignored
     *
     * @throws LogicException if startup has begun or the caller is a forked copy
     */
    public function add(callable $callback): void
    {
        if ($this->started || $this->owner !== getmypid()) {
            throw new LogicException('Main process tasks must be added by their owner before startup.');
        }
        $this->callbacks->enqueue($callback(...));
    }

    /**
     * Mounts an independent child runtime. Forks occur in mounting order; child runtimes execute concurrently.
     * Register child-owned signal handlers and create native servers/Pools inside this callback.
     *
     * @param callable(): mixed $callback Runs without arguments in the child; its return value is ignored
     *
     * @throws LogicException if startup has begun or the caller is a forked copy
     */
    public function addProcess(callable $callback): void
    {
        if ($this->started || $this->owner !== getmypid()) {
            throw new LogicException('Main process tasks must be added by their owner before startup.');
        }
        $this->callbacks->enqueue(function () use ($callback): void {
            $this->children->spawn($callback(...), $this->pidFile);
        });
        $this->hasProcesses = true;
    }

    /**
     * Runs initialization and forks mounted children, then waits for its own children to exit and be reaped.
     * SIGTERM/SIGINT trigger SIGTERM delivery in mounting order. Child PIDs remain private.
     * The PID file contains this supervisor's final PID after optional daemonization, until cleanup completes.
     * Empty queues leave runtime settings unchanged and create no daemon or PID resources.
     * Startup is allowed once, by the creator. Non-empty queues require a clean CLI before asynchronous work.
     *
     * @throws LogicException      on repeated, recursive or forked startup
     * @throws SupervisorException for an invalid runtime, daemon/PID failure, child failure or incomplete cleanup
     * @throws Throwable           if a supervisor initialization callback fails
     */
    public function start(): void
    {
        if ($this->started || $this->owner !== getmypid()) {
            throw new LogicException('The main process may only be started once by its owner.');
        }
        $this->started = true;
        if ($this->callbacks->isEmpty()) {
            return;
        }
        $stats = Coroutine::stats();
        if ('cli' !== PHP_SAPI || 0 <= Coroutine::getCid() || 0 < $stats['event_num']
            || 0 < $stats['coroutine_num'] || 0 < $stats['aio_worker_num']) {
            throw new SupervisorException('Start MainProcess in a clean CLI before reactors or asynchronous work.');
        }
        try {
            RuntimeSettings::apply($this->config);
            if ($this->hasProcesses) {
                $this->children->prepare();
            }
            if (null !== $this->config->pidFile()) {
                $this->pidFile = new PidFile($this->config->pidFile());
            }
            if ($this->config->daemon()) {
                $input = @fopen('/dev/null', 'r');
                if (!is_resource($input)) {
                    throw new SupervisorException('Unable to open daemon standard input.');
                }
                try {
                    $daemonized = Process::daemon(true, true, [
                        $input,
                    ]);
                } catch (Throwable $daemonFailure) {
                    fclose($input);

                    throw $daemonFailure;
                }
                fclose($input);
                if (!$daemonized) {
                    throw new SupervisorException('Unable to daemonize MainProcess.');
                }
                $this->owner = getmypid();
            }
            $this->pidFile?->publish();
            while (!$this->callbacks->isEmpty() && $this->children->poll()) {
                $callback = $this->callbacks->dequeue();
                $callback();
            }
            $this->children->finish();
        } catch (Throwable $error) {
            $this->children->abort();
            if ($this->config->daemon()) {
                $this->logger->error('Main process failed: ' . $error->getMessage());
            }
            throw $error;
        } finally {
            $this->pidFile?->close();
            $this->pidFile = null;
            $this->children->restore();
        }
    }
}
