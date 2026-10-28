<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Swoole\Command\SwooleStartCommand;
use SuperKernel\Swoole\Event\MainProcessCreated;
use SuperKernel\Swoole\MainProcess;
use SuperKernel\Swoole\Tests\Fixture\SwooleConfig;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;
use UnexpectedValueException;

use function bin2hex;
use function file_get_contents;
use function getmypid;
use function random_bytes;
use function sys_get_temp_dir;

final class SwooleStartCommandTest extends TestCase
{
    public function testUsesTheFrameworkDeclarationAndPreservesDirectConstructionMetadata(): void
    {
        $class = new ReflectionClass(SwooleStartCommand::class);
        self::assertSame([], $class->getAttributes(AsCommand::class));
        $declaration = $class->getAttributes(AttributeCommand::class)[0]->newInstance();
        self::assertInstanceOf(AttributeCommand::class, $declaration);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');
        $command = new SwooleStartCommand($container);
        self::assertSame('swoole:start', $declaration->name);
        self::assertSame($declaration->name, $command->getName());
        self::assertSame('Starts the supervised Swoole server and background tasks.', $declaration->description);
        self::assertSame($declaration->description, $command->getDescription());
    }

    public function testDispatchesBeforeRunningMountedCallbacks(): void
    {
        $order = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$order): object {
                self::assertInstanceOf(MainProcessCreated::class, $event);
                self::assertInstanceOf(MainProcess::class, $event->mainProcess);
                $order[] = 'dispatch';
                $event->mainProcess->add(static function () use (&$order): void {
                    $order[] = 'first';
                });
                $event->mainProcess->add(static function () use (&$order): void {
                    $order[] = 'second';
                });
                self::assertSame([
                    'dispatch',
                ], $order);
                $order[] = 'dispatched';
                return $event;
            },
        );
        $command = $this->command(
            new SwooleConfig(),
            $dispatcher,
        );

        self::assertSame(Command::SUCCESS, new CommandTester($command)->execute([]));
        self::assertSame([
            'dispatch',
            'dispatched',
            'first',
            'second',
        ], $order);
    }

    public function testListenerFailurePreventsStartup(): void
    {
        $ran = false;
        $failure = new UnexpectedValueException('Registration failed.');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$ran, $failure): object {
                self::assertInstanceOf(MainProcessCreated::class, $event);
                $event->mainProcess->add(static function () use (&$ran): void {
                    $ran = true;
                });
                throw $failure;
            },
        );
        $command = $this->command(
            new SwooleConfig(),
            $dispatcher,
        );
        $this->expectExceptionObject($failure);

        try {
            new CommandTester($command)->execute([]);
        } finally {
            self::assertFalse($ran);
        }
    }

    public function testMountedCallbackFailureStopsLaterCallbacks(): void
    {
        $ran = false;
        $failure = new UnexpectedValueException('Mounted task failed.');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$ran, $failure): object {
                self::assertInstanceOf(MainProcessCreated::class, $event);
                $event->mainProcess->add(static function () use ($failure): void {
                    throw $failure;
                });
                $event->mainProcess->add(static function () use (&$ran): void {
                    $ran = true;
                });
                return $event;
            },
        );
        $command = $this->command(
            new SwooleConfig(),
            $dispatcher,
        );
        $this->expectExceptionObject($failure);

        try {
            new CommandTester($command)->execute([]);
        } finally {
            self::assertFalse($ran);
        }
    }

    public function testCreatesAnEventForTheContainerMainProcessAndStartsIt(): void
    {
        $ran = false;
        $mainProcess = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $mainProcess->add(static function () use (&$ran): void {
            $ran = true;
        });
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $event) use ($mainProcess, &$ran): object {
                self::assertInstanceOf(MainProcessCreated::class, $event);
                self::assertSame($mainProcess, $event->mainProcess);
                self::assertFalse($ran);
                return $event;
            },
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(2))->method('get')->willReturnMap([
            [
                MainProcess::class,
                $mainProcess,
            ],
            [
                EventDispatcherInterface::class,
                $dispatcher,
            ],
        ]);

        self::assertSame(Command::SUCCESS, new CommandTester(new SwooleStartCommand($container))->execute([]));
        self::assertTrue($ran);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testResolvesRuntimeOnlyWhenExecutingAndBeforeDispatch(): void
    {
        $executing = false;
        $resolved = false;
        $mainProcess = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $event) use ($mainProcess, &$resolved): object {
                self::assertTrue($resolved);
                self::assertInstanceOf(MainProcessCreated::class, $event);
                self::assertSame($mainProcess, $event->mainProcess);
                return $event;
            },
        );
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(2))->method('get')->willReturnCallback(
            static function (string $id) use (&$executing, &$resolved, $mainProcess, $dispatcher): object {
                self::assertTrue($executing);
                if (MainProcess::class === $id) {
                    $resolved = true;
                    return $mainProcess;
                }

                self::assertSame(EventDispatcherInterface::class, $id);
                return $dispatcher;
            },
        );
        $application = new Application();
        $application->setAutoExit(false);
        $command = new SwooleStartCommand($container);
        self::assertSame([], $command->getDefinition()->getOptions());
        $application->addCommand($command);

        self::assertFalse($resolved);
        $executing = true;
        $status = $application->run(new ArgvInput([
            'console',
            'swoole:start',
        ]), new BufferedOutput());

        self::assertSame(Command::SUCCESS, $status);
        self::assertFalse($application->has('process:start'));
    }

    /** @return iterable<string, array{Throwable}> */
    public static function configurationFailures(): iterable
    {
        yield 'missing configuration' => [
            new ConfigurationNotFoundException('No Swoole configuration was declared.'),
        ];
        yield 'invalid configuration' => [
            new InvalidArgumentException('Use an absolute PID file path; daemon mode requires one.'),
        ];
    }

    #[DataProvider('configurationFailures')]
    public function testConfigurationFailurePreventsRegistration(Throwable $failure): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(2))->method('get')->willReturnCallback(
            static function (string $id) use ($dispatcher, $failure): object {
                if (EventDispatcherInterface::class === $id) {
                    return $dispatcher;
                }

                self::assertSame(MainProcess::class, $id);
                throw $failure;
            },
        );

        $this->expectExceptionObject($failure);

        new CommandTester(new SwooleStartCommand($container))->execute([]);
    }

    public function testDispatcherResolutionFailurePreventsRuntimeResolution(): void
    {
        $failure = new UnexpectedValueException('Dispatcher resolution failed.');
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::once())->method('get')
            ->with(EventDispatcherInterface::class)->willThrowException($failure);
        $this->expectExceptionObject($failure);

        new CommandTester(new SwooleStartCommand($container))->execute([]);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testEmptyRuntimeDoesNotCreateDaemonResources(): void
    {
        $path = sys_get_temp_dir() . '/sk-command-' . bin2hex(random_bytes(8)) . '.pid';
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $event): object {
                self::assertInstanceOf(MainProcessCreated::class, $event);
                return $event;
            },
        );
        $config = new SwooleConfig(
            daemon: true,
            pidFile: $path,
        );
        $command = $this->command($config, $dispatcher);

        self::assertSame('swoole:start', $command->getName());
        self::assertSame(Command::SUCCESS, new CommandTester($command)->execute([]));
        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path . '.log');
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testCommandAppliesPidConfigurationToMainProcess(): void
    {
        $path = sys_get_temp_dir() . '/sk-command-pid-' . bin2hex(random_bytes(8)) . '.pid';
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $event) use ($path): object {
                self::assertInstanceOf(MainProcessCreated::class, $event);
                self::assertFileDoesNotExist($path);
                $event->mainProcess->add(static function () use ($path): void {
                    self::assertSame(getmypid() . "\n", file_get_contents($path));
                });
                return $event;
            },
        );
        $command = $this->command(
            new SwooleConfig(pidFile: $path),
            $dispatcher,
        );

        self::assertSame(Command::SUCCESS, new CommandTester($command)->execute([]));
        self::assertFileDoesNotExist($path);
    }

    /** @return iterable<string, array{string}> */
    public static function removedOptions(): iterable
    {
        foreach ([
            '--server',
            '--no-server',
            '--daemon',
            '--pid-file=/tmp/swoole.pid',
            '--log-file=/tmp/swoole.log',
        ] as $option) {
            yield $option => [
                $option,
            ];
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    #[DataProvider('removedOptions')]
    public function testRejectsRemovedOptionsBeforeStartingTheRuntime(string $option): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->addCommand($this->unresolvedCommand());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('option does not exist.');

        $application->run(new ArgvInput([
            'console',
            'swoole:start',
            $option,
        ]), new BufferedOutput());
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testRejectsAnExtraActionBeforeStartingTheRuntime(): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->addCommand($this->unresolvedCommand());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageIs('No arguments expected for "swoole:start" command, got "start".');

        $application->run(new ArgvInput([
            'console',
            'swoole:start',
            'start',
        ]), new BufferedOutput());
    }

    /** @return iterable<string, array{list<string>, int}> */
    public static function otherConsoleRequests(): iterable
    {
        yield 'command help' => [
            [
                'swoole:start',
                '--help',
            ],
            Command::SUCCESS,
        ];
        yield 'command listing' => [
            [
                'list',
            ],
            Command::SUCCESS,
        ];
        yield 'independent swoole command' => [
            [
                'swoole:watch',
            ],
            7,
        ];
    }

    /**
     * @param list<string> $arguments
     *
     * @throws Throwable When fixture setup or the operation under test throws
     */
    #[DataProvider('otherConsoleRequests')]
    public function testOtherConsoleRequestsDoNotResolveConfigurationOrStartRuntime(
        array $arguments,
        int   $expectedStatus,
    ): void
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand($this->unresolvedCommand());
        $application->addCommand(new Command('swoole:watch')->setCode(static fn(): int => 7));

        $status = $application->run(new ArgvInput([
            'console',
            ...$arguments,
        ]), new BufferedOutput());

        self::assertSame($expectedStatus, $status);
    }

    private function command(SwooleConfig $config, EventDispatcherInterface $dispatcher): SwooleStartCommand
    {
        $logger = $this->createStub(StdoutLoggerInterface::class);
        $mainProcess = new MainProcess($logger, $config);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::exactly(2))->method('get')->willReturnMap([
            [
                MainProcess::class,
                $mainProcess,
            ],
            [
                EventDispatcherInterface::class,
                $dispatcher,
            ],
        ]);

        return new SwooleStartCommand($container);
    }

    private function unresolvedCommand(): SwooleStartCommand
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects(self::never())->method('get');

        return new SwooleStartCommand($container);
    }

}
