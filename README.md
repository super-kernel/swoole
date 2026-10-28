# SuperKernel Swoole

`super-kernel/swoole` provides Swoole console commands and main process supervision for SuperKernel.
Requires PHP 8.4.27 or later in the 8.4.x series, `ext-pcntl` and the Swoole extension `>=6.2.3 <6.3`.

## Source organization

Paths below are relative to `src/` and the package namespace.

| Directory | Responsibility |
| --- | --- |
| `Process` | `ChildProcessGroup` and `PidFile`: child supervision, signal state, and PID-lock ownership. |
| `Runtime` | `ProcessRuntime` composes a run; `RuntimeSettings` applies native runtime options. |
| `Command / Event / Config / Provider` | CLI activation, registration events, configuration contract, and validating factory. |

Implementation helpers retain an `@internal` boundary; a responsibility namespace does not make them extension points.

### Public namespaces

Use the following component declarations:

- `Process\ChildProcessGroup`
- `Process\PidFile`
- `Runtime\RuntimeSettings`
- `Runtime\ProcessRuntime`

## Command

```bash
./bin/bootstrap.php swoole:start
```

`SuperKernel\Swoole\Command\SwooleStartCommand` registers `swoole:start` with the final class-level
`SuperKernel\Command\Attribute\Command` Attribute. Its constructor receives only `Psr\Container\ContainerInterface`.
Its MainProcess and dispatcher properties use `get` hooks to resolve and cache their container entries on first access.
Creating the command, showing help or listing commands does not resolve runtime dependencies.
During execution, the command resolves the dispatcher and `MainProcess` from the container. DI injects MainProcess's
stdout logger and `Config\SwooleConfigInterface` dependencies.
`Provider\SwooleConfigProvider` requests the application's
`SuperKernel\Swoole\Config\SwooleConfigInterface` through `ConfigInterface::get()`, validates its values,
and returns that same application object through the interface binding. The command creates `new MainProcessCreated($this->mainProcess)`, dispatches it
synchronously, and starts the same MainProcess. The event is never resolved through DI or a PSR container.
It accepts no action argument or command-specific options. Symfony's standard help and output options remain
available. Configuration failures prevent event dispatch and startup, even when no runtime tasks are mounted.

MainProcess owns daemonization, its PID file, mounted child PIDs and shutdown forwarding.
[Server's listener](https://github.com/super-kernel/server/blob/main/README.md) checks its own `SwooleServerConfigInterface::enable()` configuration
before mounting a child that resolves `ServerInterface` through DI and starts it.
When Process is installed with registered tasks, its listener mounts a separate child that owns the native Pool.
Both runtimes can run concurrently beneath the main supervisor. Create native runtimes and business connections
inside their child callbacks; begin supervision in a clean CLI before reactors or asynchronous work.
This also applies to non-empty runtimes containing only initialization callbacks, because startup configures
process-wide Swoole options before any callback runs.

This package directly depends on `super-kernel/server`, `super-kernel/command`, `super-kernel/config`, `super-kernel/attribute`,
`super-kernel/contract`, `psr/container:^2.0.2`, `psr/event-dispatcher` and `symfony/console`.
It does not require `super-kernel/process`.
Process can depend on Swoole to register Pool startup; Server retains its reciprocal dependency on Swoole.

Other installed components can register independent commands such as `swoole:watch` with `SuperKernel\Command\Attribute\Command`.
Their classes must extend Symfony `Command` and participate in Composer autoloading and Attribute
discovery. Full command names and aliases must be unique; this package does not reserve the whole
`swoole:` prefix. A watch command is not implemented here.

Source declarations use `SuperKernel\Swoole\` under `src/`; test declarations use
`SuperKernel\Swoole\Tests\` under `tests/`. Neither directory contains placeholder classes.

## Main supervisor

`MainProcess::add(callable $callback)` registers initialization in the supervisor. Its parameterless `start()`
runs those callbacks once in FIFO order. A blocking initialization callback must return before startup proceeds.
`MainProcess::addProcess(callable $callback)` mounts an independent `Swoole\Process` child with no framework pipe
or automatic coroutine scheduler. Forks follow mounting order; child execution is concurrent. Callbacks take no
arguments. Register signal handlers and construct native servers or Pools inside the child callback.
Registration closes at startup, and only the creator may start the supervisor once.

Each MainProcess privately owns an `Process\ChildProcessGroup` for its child PIDs, signal mask and shutdown state.
This resource is created separately for each MainProcess and is not obtained as a shared DI service. MainProcess
keeps registration order, daemonization and PID-file lifetime; the group handles child creation, signal delivery,
waiting and reaping. Initialization failures stop and reap children before propagating the original exception.

`MainProcessCreated` exposes only the supervisor as `mainProcess`.
`ProcessRuntime::start(SwooleConfigInterface $config)` dispatches the same event for programmatic callers.
Its constructor receives the event dispatcher and stdout logger. Both entry points pass their injected logger to
each fresh MainProcess. Logging behavior belongs to the selected `StdoutLoggerInterface` implementation.
Listeners own their services and mount callbacks; supervisor configuration stays inside MainProcess.
The event exposes no process registry or child PID list.

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

Server owns its listener and native or coroutine runtime. Its child starts outside a coroutine; Server enters
its own scheduler when needed. Server startup adds no Pool worker. Process creates a fresh Pool manager in its
own child, keeping native Pool's process-wide wait loop separate from the main supervisor and Server.

## PHP compatibility

`SwooleStartCommand` requires only `Psr\Container\ContainerInterface` in its constructor.
The container must resolve `MainProcess` and `EventDispatcherInterface`; MainProcess receives its
logger and configuration through constructor injection. The command has no configuration property.
Directly constructing SwooleStartCommand supplies its name and description without resolving runtime services.
The command is intended for a single execution per framework run. MainProcess continues to reject repeated startup.

Construct `MainProcessCreated` with only the MainProcess instance.
Pass the application's `StdoutLoggerInterface` as MainProcess's first constructor argument and required `SwooleConfigInterface`
as its second argument. `ProcessRuntime` requires the dispatcher and logger; its `start()` accepts `SwooleConfigInterface`.
Server's `SwooleServerConfigInterface::enable()` controls its listener's mounting decision. Missing configuration
skips mounting. MainProcess owns daemon/PID settings; `addProcess()` mounts child runtime construction/startup.
`add()` initialization runs synchronously in FIFO order. Complete Process registrations during the
event phase; Process's listener snapshots registered entries and IPC options when it mounts its child.

Daemon/PID failures and unsuccessful child exits throw `SuperKernel\Swoole\Exception\SupervisorException`.
A child exception is logged at Error through the injected `StdoutLoggerInterface` and exits with code 1;
it cannot propagate as the same PHP object across
the fork. MainProcess stops the remaining children, reaps them, then reports the failure. Supervisor initialization
exceptions propagate unchanged after child cleanup. The console name remains `swoole:start`.

## Configuration and shutdown

Declare an application source for `SuperKernel\Swoole\Config\SwooleConfigInterface` using
`SuperKernel\Attribute\Configuration`. For example:

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

| Method | Default | Meaning |
| --- | --- | --- |
| `displayErrors()` | `true` | Controls `swoole.display_errors`, independently of PHP's `display_errors` and the logger. |
| `socketBufferSize()` | `8388608` (8 MiB) | Default Swoole socket buffer capacity in bytes, from 1 through 4294967295. |
| `enablePreemptiveScheduler()` | `false` | Enables preemptive scheduling when a runtime activates its coroutines. |

After event registration, a non-empty MainProcess applies these options before initialization and child creation.
`Runtime\RuntimeSettings` updates their INI values and uses `Swoole\Coroutine::set()` to refresh the socket default
and replace an earlier preemptive-scheduler option, while retaining unrelated coroutine options. This creates no
coroutine, reactor or asynchronous worker. Mounted children inherit the settings; they still own scheduler activation.
These are process-wide startup settings and remain applied when MainProcess returns. An empty queue leaves them unchanged.
Do not use this configuration for per-request overrides or to reconfigure an already active runtime.

The supported keys follow Swoole 6.2.3, including `swoole.socket_buffer_size`; `swoole.enable_coroutine` is not an INI directive.
`swoole.use_shortname` and `swoole.enable_library` remain PHP startup configuration; they are not methods on this
interface. Changing the library INI value after PHP starts cannot unload the already loaded library.
See the upstream [INI registration and initialization](https://github.com/swoole/swoole-src/blob/v6.2.3/ext-src/php_swoole.cc)
and [coroutine configuration](https://github.com/swoole/swoole-src/blob/v6.2.3/ext-src/swoole_coroutine_scheduler.cc).

For daemon mode, return `true` from `daemon()` and an absolute path from `pidFile()`.
Configuration lookup and validation happen when `swoole:start` resolves MainProcess and its
dependencies during execution. The provider throws `ConfigurationNotFoundException`
when Config returns `null` for a missing source and throws
`InvalidArgumentException` for invalid paths or socket buffer sizes; DI can wrap these failures in its container
resolution exception. Resolving the configuration does not apply the process-wide settings.

`Provider\SwooleConfigProvider` is registered with `FactoryProvider(SwooleConfigInterface::class)`.
It returns the exact validated application source obtained from Config. Consumers inject `SwooleConfigInterface`;
application configuration uses `#[Configuration]`, avoiding a DI Provider override that would bypass validation.
Keep getter values stable throughout the runtime; the factory does not copy or freeze the source.

DI retains MainProcess and its configuration; the command creates the event explicitly around that supervisor.
The supervisor acquires the exclusive PID-file lock before daemonization and writes its own final
PID after daemonization, before initialization and child startup. It holds the lock until all mounted children
have been reaped. Child PIDs remain private; forked children close their inherited PID-file handle without
unlocking the supervisor's open-file description. The file never contains a Server, Pool or worker PID.
MainProcess only calls its injected `StdoutLoggerInterface` at Error level for daemon supervisor failures and child
exceptions. The selected provider determines the output destination and behavior. The default implementation writes
to stdout/the terminal; an application provider can use `LoggerFactory` to construct file logging and supply an
implementation of `StdoutLoggerInterface` through the application's DI bindings.
MainProcess does not own or manage log streams, capture output, or forward it to another destination. Daemonization
leaves the logger's output environment unchanged: only stdin is redirected to `/dev/null`, without closing or
redirecting stdout/stderr. Swoole defines no log-file setting or fallback PID-adjacent log file.
Launcher success or a PID file does not indicate child readiness. An empty MainProcess queue creates no daemon or
PID file and emits no log records.

Send SIGTERM or SIGINT to the PID in this file. MainProcess sends SIGTERM to its live direct children in mounting
order, then waits for all of them and removes its PID file. Sending order does not determine callback execution
or exit order. Each child runtime handles its descendants: the Pool parent shuts down/reaps Pool workers, while
Server owns its native workers. MainProcess waits for specific recorded PIDs, preserving unrelated child exits.
The signal wait uses PHP's [PCNTL signal mask](https://www.php.net/manual/en/function.pcntl-sigprocmask.php) and
[signal waiting](https://www.php.net/manual/en/function.pcntl-sigwaitinfo.php); the original mask is restored on return.

A normally returning child is reaped without replacement. An unsuccessful child exit stops its remaining siblings.
There is no automatic restart or shutdown deadline at the main-supervisor level. A child that ignores SIGTERM can
delay shutdown indefinitely. Native Pool retains its own worker replacement and shutdown behavior; see
[Process's lifecycle contract](https://github.com/super-kernel/process/blob/main/README.md#pool-lifecycle-and-ipc).

## Development

Use a PHP 8.4 Composer in this package's checkout:

```bash
composer install
composer validate --strict
composer test
composer format:check
composer format
```
