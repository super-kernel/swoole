<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Swoole

`super-kernel/swoole` provides the `swoole:start` console command and main process supervision for SuperKernel. It requires `ext-pcntl` and the Swoole extension `>=6.2.3 <6.3`.

## Command

```bash
./bin/bootstrap.php swoole:start
```

`SuperKernel\Swoole\Command\SwooleStartCommand` registers `swoole:start`. Creating the command, showing help, or listing commands does not resolve runtime dependencies. During execution the command resolves `MainProcess` and the event dispatcher from the container, synchronously dispatches `new MainProcessCreated($mainProcess)`, and starts that same `MainProcess`. It accepts no action argument or command-specific options; Symfony's standard help and output options remain available. Configuration failures prevent event dispatch and startup, even when no runtime tasks are mounted.

[Server](https://github.com/super-kernel/server/blob/main/README.md) mounts a child that starts `ServerInterface` when its own `SwooleServerConfigInterface::enable()` returns true. When `super-kernel/process` is installed with registered tasks, it mounts a separate child that owns the native Pool. Both runtimes can run concurrently beneath the main supervisor. Create native runtimes and business connections inside their child callbacks, and begin supervision in a clean CLI before reactors or asynchronous work. This also applies to runtimes containing only initialization callbacks, because startup configures process-wide Swoole options before any callback runs.

Other installed components can register independent commands such as `swoole:watch` with `SuperKernel\Command\Attribute\Command`. Full command names and aliases must be unique; this package does not reserve the whole `swoole:` prefix.

## Main supervisor

`MainProcess::add(callable $callback)` registers initialization in the supervisor. Its parameterless `start()` runs those callbacks once in FIFO order; a blocking initialization callback must return before startup proceeds. `MainProcess::addProcess(callable $callback)` mounts an independent `Swoole\Process` child with no framework pipe or automatic coroutine scheduler. Forks follow mounting order; child execution is concurrent. Callbacks take no arguments. Register signal handlers and construct native servers or Pools inside the child callback. Registration closes at startup, and only the creator may start the supervisor, once.

```php
use SuperKernel\Swoole\MainProcess;

// $main is the application's injected MainProcess service.
$main->add(static function (): void {
    // Initialize supervisor-owned state here.
});
$main->addProcess(static function (): void {
    // Create and run a child-owned runtime here.
});
$main->start(); // Returns after all mounted children have exited and been reaped.
```

`MainProcessCreated` exposes only the supervisor as `mainProcess`; listeners use it to mount their callbacks, and the event exposes no process registry or child PID list. `ProcessRuntime::start(SwooleConfigInterface $config)` dispatches the same event for programmatic callers.

When constructing these services directly:

- `MainProcess` takes the application's `StdoutLoggerInterface` and its required `SwooleConfigInterface`.
- `MainProcessCreated` takes only the `MainProcess` instance.
- `ProcessRuntime` takes the event dispatcher and the logger, and passes the logger to each fresh `MainProcess`.
- `SwooleStartCommand` takes only `Psr\Container\ContainerInterface`; direct construction supplies its name and description without resolving runtime services. The command is meant for a single execution per framework run, and `MainProcess` rejects repeated startup.

Daemon and PID failures and unsuccessful child exits throw `SuperKernel\Swoole\Exception\SupervisorException`. A child exception is logged at Error level through the injected `StdoutLoggerInterface` and exits with code 1; it cannot cross the fork as the same PHP object. MainProcess then stops the remaining children, reaps them, and reports the failure. Supervisor initialization exceptions propagate unchanged after the children are stopped and reaped.

## Configuration and shutdown

Declare an application source for `SuperKernel\Swoole\Config\SwooleConfigInterface` using `SuperKernel\Attribute\Configuration`. For example:

```php
namespace Src\Config;

use SuperKernel\Attribute\Configuration;
use SuperKernel\Swoole\Config\SwooleConfigInterface;

#[Configuration(SwooleConfigInterface::class)]
final readonly class SwooleConfig implements SwooleConfigInterface
{
    public function daemon(): bool
    {
        return false;
    }

    public function pidFile(): ?string
    {
        return null;
    }

    public function displayErrors(): bool
    {
        return true;
    }

    public function socketBufferSize(): int
    {
        return 8 * 1024 * 1024;
    }

    public function enablePreemptiveScheduler(): bool
    {
        return false;
    }
}
```

Inject `SwooleConfigInterface`; the application source supplies the defaults below.

| Method                        | Default           | Meaning                                                                                   |
|-------------------------------|-------------------|-------------------------------------------------------------------------------------------|
| `displayErrors()`             | `true`            | Controls `swoole.display_errors`, independently of PHP's `display_errors` and the logger. |
| `socketBufferSize()`          | `8388608` (8 MiB) | Default Swoole socket buffer capacity in bytes, from 1 through 4294967295.                |
| `enablePreemptiveScheduler()` | `false`           | Enables preemptive scheduling when a runtime activates its coroutines.                    |

After event registration, a MainProcess with registered callbacks applies these options before initialization and child creation. It updates their INI values and uses `Swoole\Coroutine::set()` to refresh the socket default and replace an earlier preemptive-scheduler option, while keeping unrelated coroutine options. This creates no coroutine, reactor or asynchronous worker. Mounted children inherit the settings and still own scheduler activation. These are process-wide startup settings and remain applied when MainProcess returns; an empty queue leaves them unchanged. Do not use this configuration for per-request overrides or to reconfigure an already active runtime.

The supported keys follow Swoole 6.2.3, including `swoole.socket_buffer_size`; `swoole.enable_coroutine` is not an INI directive. `swoole.use_shortname` and `swoole.enable_library` remain PHP startup configuration and are not methods on this interface. Changing the library INI value after PHP starts cannot unload the already loaded library. See the upstream [INI registration and initialization](https://github.com/swoole/swoole-src/blob/v6.2.3/ext-src/php_swoole.cc) and [coroutine configuration](https://github.com/swoole/swoole-src/blob/v6.2.3/ext-src/swoole_coroutine_scheduler.cc).

For daemon mode, return `true` from `daemon()` and an absolute path from `pidFile()`.

The configuration is looked up and validated when `swoole:start` resolves MainProcess during execution, and the container supplies that same validated application object as `SwooleConfigInterface`. A missing source raises `ConfigurationNotFoundException`, and invalid paths or socket buffer sizes raise `InvalidArgumentException`; DI can wrap these failures in its container resolution exception. Resolving the configuration does not apply the process-wide settings. Customize values with `#[Configuration]`; a DI `Provider` override would bypass validation. Keep getter values stable throughout the runtime: the source is not copied or frozen.

The supervisor acquires an exclusive PID-file lock before daemonization and writes its own final PID after daemonization, before initialization and child startup. It holds the lock until all mounted children have been reaped. Child PIDs remain private; forked children close their inherited PID-file handle without releasing the supervisor's lock. The file never contains a Server, Pool or worker PID. Launcher success or a PID file does not indicate child readiness. An empty MainProcess queue creates no daemon or PID file and emits no log records.

MainProcess only logs, at Error level through its injected `StdoutLoggerInterface`, daemon supervisor failures and child exceptions. The selected logger determines the output destination. The default writes to stdout; an application can bind its own `StdoutLoggerInterface`, for example built with `LoggerFactory`, to log to a file. MainProcess does not capture or forward output. Daemonization redirects only stdin to `/dev/null` and leaves stdout and stderr unchanged. There is no log-file setting.

Send SIGTERM or SIGINT to the PID in this file. MainProcess sends SIGTERM to its live direct children in mounting order, then waits for all of them and removes its PID file. Sending order does not determine callback execution or exit order. Each child runtime handles its descendants: the Pool parent shuts down and reaps Pool workers, while Server owns its native workers. MainProcess waits only for the PIDs it recorded, so unrelated child exits are preserved. The signal wait uses PHP's [PCNTL signal mask](https://www.php.net/manual/en/function.pcntl-sigprocmask.php) and [signal waiting](https://www.php.net/manual/en/function.pcntl-sigwaitinfo.php); the original mask is restored on return.

A normally returning child is reaped without replacement. An unsuccessful child exit stops its remaining siblings. There is no automatic restart or shutdown deadline at the main-supervisor level, so a child that ignores SIGTERM can delay shutdown indefinitely. Native Pool keeps its own worker replacement and shutdown behavior; see [Process's lifecycle contract](https://github.com/super-kernel/process/blob/main/README.md#pool-lifecycle-and-ipc).
