<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Swoole

`super-kernel/swoole` 提供 `swoole:start` 命令，以及把应用的各个 Swoole 运行时（例如服务器和进程池）作为并发子进程进行监管的主进程。

## 安装

```bash
composer require super-kernel/swoole
```

本包需要 `pcntl` 扩展和 `>=6.2.3 <6.3` 的 Swoole 扩展，并会安装 `super-kernel/server`。应用必须声明一个 `SwooleConfigInterface` 配置源；该命令在控制台应用中运行（例如由 `super-kernel/skeleton` 创建的应用），并需要 SKERNEL 构建项目。

## 提供的能力

- `swoole:start` 命令：先让每个监听器挂载自己的工作，再启动主进程。
- `MainProcess`：按顺序运行初始化回调，并监管挂载在它上面的子进程。
- `MainProcessCreated` 事件：各个包和应用通过它挂载自己的工作。
- 守护进程模式，使用一个加了排他锁、保存监管进程 PID 的 PID 文件。
- 收到 `SIGTERM` 或 `SIGINT` 时关闭：命令返回之前，每个子进程都会被停止并回收。
- `SwooleConfigInterface`：经过校验的守护进程模式、PID 文件和进程级 Swoole 选项的设置。

它不会：

- 重启退出或失败的子进程；
- 对忽略 `SIGTERM` 的子进程强制关闭期限；
- 暴露子进程 PID 或进程注册表；
- 写日志文件或捕获子进程的输出；
- 自己创建服务器或进程池；它们由 `super-kernel/server` 和 `super-kernel/process` 挂载。

## 快速开始

在由 `super-kernel/skeleton` 创建的应用中（它已经声明了 `src/Config/SwooleConfig.php`），下面的监听器会在 HTTP 服务器旁边挂载一个初始化回调和一个子进程。将它保存为 `src/Listener/MountReportWriter.php`：

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

构建项目并启动它：

```bash
skernel build
php bin/bootstrap.php swoole:start
```

它会在服务器的日志行旁边打印 `Supervisor initialized` 和 `Report writer finished`。服务器会继续运行；按 Ctrl+C 停止它。

## 公开接口

| 名称 | 类别 | 用途 |
| --- | --- | --- |
| `swoole:start` | 命令 | 分发 `MainProcessCreated`，然后启动主进程，并在每个子进程都退出后返回。 |
| `SuperKernel\Swoole\Event\MainProcessCreated` | 事件 | 在任何已挂载的内容运行之前，以 `mainProcess` 携带 `MainProcess`。 |
| `SuperKernel\Swoole\MainProcess` | 类 | `add()` 挂载初始化回调，`addProcess()` 挂载子进程，`start()` 运行它们。 |
| `SuperKernel\Attribute\Configuration` | 注解 | 声明应用的 `SwooleConfigInterface` 配置源。 |
| `SuperKernel\Swoole\Config\SwooleConfigInterface` | 接口 | 守护进程模式、PID 文件、`swoole.display_errors`、套接字缓冲区大小和抢占式调度。 |
| `SuperKernel\Swoole\Runtime\ProcessRuntime` | 类 | `start($config)` 创建主进程、分发其事件并启动它，无需通过命令。 |

## 需要了解的行为

- 配置在 `swoole:start` 运行时、任何监听器之前读取并校验。缺少配置源会抛出 `SuperKernel\Config\Exception\ConfigurationNotFoundException`，消息为 `No configuration source is registered for SuperKernel\Swoole\Config\SwooleConfigInterface.`；相对的 PID 路径，或者没有 PID 路径的守护进程模式，会抛出 `Use an absolute PID file path; daemon mode requires one.`
- 回调不接收参数，按挂载顺序各运行一次；子进程并发运行。在 `start()` 之后挂载或第二次启动会抛出 `LogicException`。
- 挂载了内容时，`start()` 必须在干净的 CLI 中、任何协程、事件循环或异步工作之前运行，否则会抛出 `Start MainProcess in a clean CLI before reactors or asynchronous work.` 服务器、进程池和连接请在子进程回调中创建。
- 子进程中的异常会被记录为 `Mounted process failed: <message>`，并以退出码 1 结束该子进程；随后监管进程停止并回收其他子进程，抛出 `SuperKernel\Swoole\Exception\SupervisorException`，消息为 `A mounted process failed with exit code 1 and signal 0.` 初始化回调中的异常会在子进程停止之后原样传出。
- 没有挂载任何内容时，`start()` 立即返回，不会创建守护进程、PID 文件或日志记录。
- 监听器从构建元数据中发现：添加、删除或修改监听器或配置类之后，请重新运行 `skernel build`，或者用会先构建的 `skernel run` 启动。

## 文档

开发者文档介绍了挂载、配置和运行时设置、守护进程模式与 PID 文件、信号与关闭、日志、错误消息以及不要这样做的事项：[SuperKernel Swoole](https://github.com/super-kernel/.github/blob/main/docs/components/swoole.zh-CN.md)。

## 许可证

基于 MIT 许可证发布，详见 [LICENSE](LICENSE)。
