<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use RuntimeException;
use SuperKernel\Attribute\Listener;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Contract\ContainerInterface;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Server\Config\SwooleServerConfigInterface;
use SuperKernel\Server\Contract\ServerInterface;
use SuperKernel\Server\Listener\MainProcessCreatedListener as ServerMainProcessCreatedListener;
use SuperKernel\Swoole\Event\MainProcessCreated;
use SuperKernel\Swoole\MainProcess;
use SuperKernel\Swoole\Runtime\ProcessRuntime;
use SuperKernel\Swoole\Tests\Fixture\SwooleConfig;
use Swoole\Coroutine;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function get_object_vars;
use function getmypid;
use function posix_getppid;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const FILE_APPEND;

final class MainProcessTest extends TestCase
{
    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testCallbacksRunInFifoOrderOnlyAfterStart(): void
    {
        $recorder = new class() {
            /** @var list<string> */
            public array $calls = [];

            public function record(): void
            {
                $this->calls[] = 'method';
            }

            public function __invoke(): void
            {
                $this->calls[] = 'invokable';
            }
        };
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $main->add(static function () use ($recorder): string {
            $recorder->calls[] = 'closure-start';
            $recorder->calls[] = 'closure-end';
            return 'ignored';
        });
        $main->add([
            $recorder,
            'record',
        ]);
        $main->add('getmypid');
        $main->add($recorder);
        $main->add($recorder->record(...));
        self::assertSame([], $recorder->calls);

        $main->start();

        self::assertSame([
            'closure-start',
            'closure-end',
            'method',
            'invokable',
            'method',
        ], $recorder->calls);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testCallbackFailureStopsLaterTasksAndCannotBeRetried(): void
    {
        $failure = new RuntimeException('Mounted task failed.');
        $calls = [];
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $main->add(static function () use (&$calls): void {
            $calls[] = 'first';
        });
        $main->add(static function () use ($failure): never {
            throw $failure;
        });
        $main->add(static function () use (&$calls): void {
            $calls[] = 'last';
        });

        try {
            $main->start();
            self::fail('A mounted task failure must propagate.');
        } catch (RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame([
            'first',
        ], $calls);
        $this->expectException(LogicException::class);
        $main->start();
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testEmptyQueueCanOnlyBeStartedOnce(): void
    {
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $main->start();

        $this->expectException(LogicException::class);
        $main->start();
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRecursiveStartIsRejected(): void
    {
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $main->add($main->start(...));

        $this->expectException(LogicException::class);
        $main->start();
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testCallbacksCannotMountTasksAfterStartupBegins(): void
    {
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $main->add(static function () use ($main): void {
            $main->add(static fn(): int => 1);
        });

        $this->expectException(LogicException::class);
        $main->start();
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRuntimeKeepsConfigurationOnMainProcessAndDispatchesBeforeStartup(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sk-runtime-pid-');
        unlink($path);
        $config = new SwooleConfig(pidFile: $path);
        $dispatched = false;
        $mounted = false;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $event) use ($path, &$dispatched, &$mounted): object {
                self::assertInstanceOf(MainProcessCreated::class, $event);
                self::assertSame([
                    'mainProcess' => $event->mainProcess,
                ], get_object_vars($event));
                self::assertFileDoesNotExist($path);
                $event->mainProcess->add(static function () use ($path, &$dispatched, &$mounted): void {
                    self::assertTrue($dispatched);
                    self::assertSame(getmypid() . "\n", file_get_contents($path));
                    $mounted = true;
                });
                self::assertFalse($mounted);
                $dispatched = true;
                return $event;
            },
        );

        new ProcessRuntime($dispatcher, $this->createStub(StdoutLoggerInterface::class))->start($config);

        self::assertTrue($mounted);
        self::assertFileDoesNotExist($path);
    }

    /** @throws Throwable if the mounted Server callback fails */
    public function testServerListenerResolvesAndStartsTheServerInAMountedChild(): void
    {
        self::assertCount(
            1,
            new ReflectionClass(ServerMainProcessCreatedListener::class)->getAttributes(Listener::class),
        );
        $log = tempnam(sys_get_temp_dir(), 'sk-mounted-server-');
        $server = $this->createStub(ServerInterface::class);
        $server->method('start')->willReturnCallback(static function () use ($log): void {
            file_put_contents($log, getmypid() . ':' . posix_getppid() . ':' . Coroutine::getCid(), FILE_APPEND);
        });
        $configuration = $this->createStub(SwooleServerConfigInterface::class);
        $configuration->method('enable')->willReturn(true);
        $config = $this->createMock(ConfigInterface::class);
        $config->expects(self::once())->method('has')->with(SwooleServerConfigInterface::class)->willReturn(true);
        $config->expects(self::never())->method('get');
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (
                string $id,
            ) use ($server, $log, $configuration): ServerInterface|SwooleServerConfigInterface {
                if (SwooleServerConfigInterface::class === $id) {
                    return $configuration;
                }
                file_put_contents($log, 'resolved:');
                return $server;
            },
        );
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());

        new ServerMainProcessCreatedListener($container, $config)->process(new MainProcessCreated($main));

        self::assertSame('', file_get_contents($log));
        try {
            $main->start();
            self::assertMatchesRegularExpression(
                '/^resolved:[1-9][0-9]*:' . getmypid() . ':-1$/',
                file_get_contents($log),
            );
        } finally {
            unlink($log);
        }
    }
}
