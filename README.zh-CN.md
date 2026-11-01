<p align="right">
  <a href="README.md">English</a> | <strong>中文文档</strong>
</p>

# SuperKernel Swoole

`super-kernel/swoole` 为 SuperKernel 提供 `swoole:start` 控制台命令和主进程监管。它需要 `ext-pcntl` 以及 `>=6.2.3 <6.3` 版本的 Swoole 扩展。

## 命令

```bash
./bin/bootstrap.php swoole:start
```

`SuperKernel\Swoole\Command\SwooleStartCommand` 注册 `swoole:start`。创建命令、显示帮助或列出命令都不会解析运行时依赖。执行时，命令从容器中解析 `MainProcess` 和事件调度器，同步分发 `new MainProcessCreated($mainProcess)`，然后启动同一个 `MainProcess`。它不接受动作参数，也没有命令专属的选项；Symfony 标准的帮助和输出选项仍然可用。配置失败会阻止事件分发和启动，即使没有挂载任何运行时任务。

当 [Server](https://github.com/super-kernel/server/blob/main/README.md) 自己的 `SwooleServerConfigInterface::enable()` 返回 true 时，它会挂载一个启动 `ServerInterface` 的子进程。安装了 `super-kernel/process` 且有已注册的任务时，它会另外挂载一个持有原生 Pool 的子进程。两种运行时可以在主监管进程下并行运行。请在各自的子进程回调中创建原生运行时和业务连接，并在干净的 CLI 中、在任何 reactor 或异步工作开始之前启动监管。只包含初始化回调的运行时也是如此，因为启动时会在任何回调运行之前配置进程级的 Swoole 选项。

其他已安装的组件可以用 `SuperKernel\Command\Attribute\Command` 注册独立的命令，例如 `swoole:watch`。完整的命令名和别名必须唯一；本包不会保留整个 `swoole:` 前缀。

## 主监管进程

`MainProcess::add(callable $callback)` 在监管进程中注册初始化回调。不带参数的 `start()` 按先进先出的顺序把这些回调各运行一次；阻塞的初始化回调必须返回后，启动才会继续。`MainProcess::addProcess(callable $callback)` 挂载一个独立的 `Swoole\Process` 子进程，不带框架管道，也不自动启用协程调度器。fork 按挂载顺序进行，子进程并发执行。回调不接收参数。请在子进程回调中注册信号处理器，并构造原生服务器或 Pool。启动时注册即被关闭，只有创建者可以启动监管进程，且只能启动一次。

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

`MainProcessCreated` 只通过 `mainProcess` 暴露监管进程；监听器用它挂载自己的回调，事件不暴露进程注册表或子进程 PID 列表。`ProcessRuntime::start(SwooleConfigInterface $config)` 为以编程方式调用的代码分发同一个事件。

直接构造这些服务时：

- `MainProcess` 接收应用的 `StdoutLoggerInterface` 和必需的 `SwooleConfigInterface`。
- `MainProcessCreated` 只接收 `MainProcess` 实例。
- `ProcessRuntime` 接收事件调度器和日志器，并把日志器传给每个新建的 `MainProcess`。
- `SwooleStartCommand` 只接收 `Psr\Container\ContainerInterface`；直接构造时会提供其名称和描述，但不解析运行时服务。这个命令在每次框架运行中只应执行一次，`MainProcess` 会拒绝重复启动。

守护进程和 PID 的失败以及子进程的失败退出会抛出 `SuperKernel\Swoole\Exception\SupervisorException`。子进程中的异常会通过注入的 `StdoutLoggerInterface` 以 Error 级别记录，并以退出码 1 退出；它无法作为同一个 PHP 对象跨越 fork 传递。随后 MainProcess 停止其余子进程，回收它们，并报告失败。监管进程初始化时的异常，会在子进程被停止并回收之后原样传播。

## 配置与关闭

用 `SuperKernel\Attribute\Configuration` 为 `SuperKernel\Swoole\Config\SwooleConfigInterface` 声明一个应用来源，例如：

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

注入 `SwooleConfigInterface`；由应用来源提供下列默认值。

| 方法                          | 默认值             | 含义                                                                    |
|-------------------------------|--------------------|-------------------------------------------------------------------------|
| `displayErrors()`             | `true`             | 控制 `swoole.display_errors`，与 PHP 的 `display_errors` 和日志器无关。 |
| `socketBufferSize()`          | `8388608`（8 MiB） | Swoole socket 缓冲区的默认容量，单位为字节，取值范围 1 到 4294967295。  |
| `enablePreemptiveScheduler()` | `false`            | 在运行时激活其协程时启用抢占式调度。                                    |

事件注册完成后，有已注册回调的 MainProcess 会在初始化和创建子进程之前应用这些选项。它更新相应的 INI 值，并用 `Swoole\Coroutine::set()` 刷新 socket 默认值、替换之前设置的抢占式调度选项，同时保留其他无关的协程选项。这不会创建任何协程、reactor 或异步工作进程。被挂载的子进程会继承这些设置，但仍由它们自己负责激活调度器。这些是进程级的启动设置，在 MainProcess 返回后依然生效；队列为空时不会改变它们。不要用这份配置做针对单个请求的覆盖，也不要用它重新配置已经活动的运行时。

支持的键遵循 Swoole 6.2.3，包括 `swoole.socket_buffer_size`；`swoole.enable_coroutine` 不是 INI 指令。`swoole.use_shortname` 和 `swoole.enable_library` 仍属于 PHP 启动配置，不是这个接口上的方法。PHP 启动之后再修改 library 的 INI 值，无法卸载已经加载的库。参见上游的 [INI 注册与初始化](https://github.com/swoole/swoole-src/blob/v6.2.3/ext-src/php_swoole.cc) 和 [协程配置](https://github.com/swoole/swoole-src/blob/v6.2.3/ext-src/swoole_coroutine_scheduler.cc)。

使用守护进程模式时，让 `daemon()` 返回 `true`，并让 `pidFile()` 返回一个绝对路径。

`swoole:start` 在执行期间解析 MainProcess 时，才会查找并校验配置，容器以 `SwooleConfigInterface` 提供经过校验的同一个应用对象。来源缺失时抛出 `ConfigurationNotFoundException`，路径或 socket 缓冲区大小无效时抛出 `InvalidArgumentException`；DI 可能把这些失败包装在其容器解析异常中。解析配置本身不会应用这些进程级设置。请用 `#[Configuration]` 定制取值；DI `Provider` 覆盖会绕过校验。运行期间请保持 getter 的取值稳定：来源不会被复制或冻结。

监管进程在守护进程化之前取得 PID 文件的排他锁，并在守护进程化之后、初始化和启动子进程之前，写入它自己最终的 PID。它会一直持有该锁，直到所有已挂载的子进程都被回收。子进程的 PID 不对外暴露；fork 出的子进程会关闭继承来的 PID 文件句柄，但不会释放监管进程的锁。该文件从不包含 Server、Pool 或工作进程的 PID。启动器成功返回或 PID 文件存在，都不代表子进程已经就绪。MainProcess 队列为空时，不会守护进程化，不会创建 PID 文件，也不输出任何日志。

MainProcess 只通过注入的 `StdoutLoggerInterface` 以 Error 级别记录守护监管的失败和子进程中的异常。输出目的地由所选的日志器决定。默认写到 stdout；应用可以绑定自己的 `StdoutLoggerInterface`（例如用 `LoggerFactory` 构建）来写入文件。MainProcess 不会捕获或转发输出。守护进程化只把 stdin 重定向到 `/dev/null`，stdout 和 stderr 保持不变。没有日志文件设置。

向该文件中的 PID 发送 SIGTERM 或 SIGINT。MainProcess 按挂载顺序向仍存活的直接子进程发送 SIGTERM，然后等待它们全部退出，并删除其 PID 文件。发送顺序并不决定回调的执行顺序或退出顺序。每个子运行时负责处理自己的后代：Pool 父进程关闭并回收 Pool 工作进程，Server 负责其原生工作进程。MainProcess 只等待它记录下的 PID，因此无关子进程的退出状态会被保留。信号等待使用 PHP 的 [PCNTL 信号屏蔽](https://www.php.net/manual/en/function.pcntl-sigprocmask.php) 和 [信号等待](https://www.php.net/manual/en/function.pcntl-sigwaitinfo.php)；返回时会恢复原来的屏蔽字。

正常返回的子进程会被回收，不会被替换。子进程失败退出会停止其余的兄弟进程。主监管进程层面没有自动重启或关闭期限，因此忽略 SIGTERM 的子进程可能无限期地拖延关闭。原生 Pool 保留其自身的工作进程替换和关闭行为；参见 [Process 的生命周期契约](https://github.com/super-kernel/process/blob/main/README.md#pool-lifecycle-and-ipc)。
