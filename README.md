<p align="right">
  <strong>English</strong> | <a href="README.zh-CN.md">中文文档</a>
</p>

# SuperKernel Swoole

`super-kernel/swoole` provides the `swoole:start` command and the main process that supervises an application's Swoole runtimes, such as the server and the process pool, as concurrent child processes.

## Installation

```bash
composer require super-kernel/swoole
```

The package needs the `pcntl` extension and the Swoole extension `>=6.2.3 <6.3`, and it installs `super-kernel/server`. The application must declare a `SwooleConfigInterface` configuration source; the command runs in a console application, such as one created from `super-kernel/skeleton`, and needs SKERNEL to build the project.

## What it provides

- The `swoole:start` command, which lets every listener mount its work and then starts the main process.
- `MainProcess`, which runs initialization callbacks in order and supervises the child processes mounted on it.
- The `MainProcessCreated` event, through which packages and applications mount their work.
- Daemon mode with an exclusively locked PID file that holds the supervisor's PID.
- Shutdown on `SIGTERM` or `SIGINT`: every child is stopped and reaped before the command returns.
- `SwooleConfigInterface`, the validated settings for daemon mode, the PID file, and process-wide Swoole options.

It does not:

- restart a child that exits or fails;
- enforce a shutdown deadline on a child that ignores `SIGTERM`;
- expose child PIDs or a process registry;
- write a log file or capture the children's output;
- create servers or pools itself; `super-kernel/server` and `super-kernel/process` mount them.

## Quick start

In an application created from `super-kernel/skeleton`, which already declares `src/Config/SwooleConfig.php`, this listener mounts an initialization callback and a child process next to the HTTP server. Save it as `src/Listener/MountReportWriter.php`:

```php
<?php

declare(strict_types=1);

namespace Src\Listener;

use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\ListenerInterface;
use SuperKernel\Swoole\Event\MainProcessCreated;

#[Listener]
final readonly class MountReportWriter implements ListenerInterface
{
    public function listen(): array
    {
        return [
            MainProcessCreated::class,
        ];
    }

    public function process(object $event): void
    {
        assert($event instanceof MainProcessCreated);

        $event->mainProcess->add(static function (): void {
            echo 'Supervisor initialized', PHP_EOL;
        });

        $event->mainProcess->addProcess(static function (): void {
            echo 'Report writer finished', PHP_EOL;
        });
    }
}
```

Build the project and start it:

```bash
skernel build
php bin/bootstrap.php swoole:start
```

It prints `Supervisor initialized` and `Report writer finished` next to the server's log lines. The server keeps running; press Ctrl+C to stop it.

## Public surface

| Name | Kind | Purpose |
| --- | --- | --- |
| `swoole:start` | Command | Dispatches `MainProcessCreated`, then starts the main process and returns when every child has exited. |
| `SuperKernel\Swoole\Event\MainProcessCreated` | Event | Carries the `MainProcess` as `mainProcess` before anything mounted has run. |
| `SuperKernel\Swoole\MainProcess` | Class | `add()` mounts an initialization callback, `addProcess()` mounts a child process, `start()` runs them. |
| `SuperKernel\Attribute\Configuration` | Attribute | Declares the application's `SwooleConfigInterface` source. |
| `SuperKernel\Swoole\Config\SwooleConfigInterface` | Interface | Daemon mode, PID file, `swoole.display_errors`, socket buffer size, and preemptive scheduling. |
| `SuperKernel\Swoole\Runtime\ProcessRuntime` | Class | `start($config)` creates a main process, dispatches its event, and starts it without the command. |

## Behavior to know

- The configuration is read and validated when `swoole:start` runs, before any listener. A missing source raises `SuperKernel\Config\Exception\ConfigurationNotFoundException` with `No configuration source is registered for SuperKernel\Swoole\Config\SwooleConfigInterface.`; a relative PID path, or daemon mode without one, raises `Use an absolute PID file path; daemon mode requires one.`
- Callbacks take no arguments and run once, in the order they were mounted; children run concurrently. Mounting after `start()` or starting twice raises `LogicException`.
- With anything mounted, `start()` must run in a clean CLI, before any coroutine, event loop, or asynchronous work: otherwise it raises `Start MainProcess in a clean CLI before reactors or asynchronous work.` Create servers, pools, and connections inside the child callback.
- An exception in a child is logged as `Mounted process failed: <message>` and ends that child with exit code 1; the supervisor then stops and reaps the other children and raises `SuperKernel\Swoole\Exception\SupervisorException` with `A mounted process failed with exit code 1 and signal 0.` An exception in an initialization callback propagates unchanged after the children are stopped.
- With nothing mounted, `start()` returns at once and creates no daemon, PID file, or log record.
- Listeners are found in the build metadata: run `skernel build` again after adding, removing, or changing a listener or the configuration class, or start with `skernel run`, which builds first.

## Documentation

The developer documentation covers mounting, configuration and runtime settings, daemon mode and the PID file, signals and shutdown, logging, the error messages, and what not to do: [SuperKernel Swoole](https://github.com/super-kernel/.github/blob/main/docs/components/swoole.md).

## License

Released under the MIT License. See [LICENSE](LICENSE).
