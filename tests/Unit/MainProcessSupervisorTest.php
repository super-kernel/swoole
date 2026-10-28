<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Swoole\Exception\SupervisorException;
use SuperKernel\Swoole\MainProcess;
use SuperKernel\Swoole\Tests\Fixture\SwooleConfig;
use Swoole\Process;
use Throwable;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function pcntl_sigprocmask;
use function pcntl_waitpid;
use function pcntl_wexitstatus;
use function posix_kill;
use function random_bytes;
use function sys_get_temp_dir;
use function unlink;

use const SIG_BLOCK;
use const SIG_SETMASK;
use const SIGTERM;
use const SIGUSR1;

final class MainProcessSupervisorTest extends TestCase
{
    /** @throws Throwable if either supervisor cannot complete child cleanup */
    public function testMainProcessesKeepSeparateChildFailureAndShutdownState(): void
    {
        $logger = $this->createStub(StdoutLoggerInterface::class);
        $config = new SwooleConfig();
        $first = new MainProcess($logger, $config);
        $second = new MainProcess($logger, $config);
        $first->addProcess(static function (): void {
            throw new RuntimeException('Intentional child failure.');
        });
        $ran = false;
        $second->add(static function () use (&$ran): void {
            $ran = true;
        });
        $second->addProcess(static function (): void {
        });

        try {
            $first->start();
            self::fail('A failed child must be reported by its supervisor.');
        } catch (SupervisorException $error) {
            self::assertStringContainsString('exit code 1', $error->getMessage());
        }

        $second->start();

        self::assertTrue($ran);
    }

    /** @throws Throwable if supervision fails while handling the pending termination signal */
    public function testTerminationDuringInitializationSkipsLaterCallbacksAndChildStartup(): void
    {
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $main->add(static function (): void {
            self::assertTrue(posix_kill(getmypid(), SIGTERM));
        });
        $ran = false;
        $main->add(static function () use (&$ran): void {
            $ran = true;
        });
        $main->addProcess(static function (): void {
            throw new RuntimeException('A child must not start after termination was requested.');
        });

        $main->start();

        self::assertFalse($ran);
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testInitializationSeesTheSupervisorPidAndFailureCleansItUp(): void
    {
        $path = $this->pidPath();
        $failure = new RuntimeException('Initialization failed.');
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig(pidFile: $path));
        $main->add(static function () use ($path, $failure): never {
            self::assertSame(getmypid() . "\n", file_get_contents($path));
            throw $failure;
        });
        self::assertFileDoesNotExist($path);
        $this->expectExceptionObject($failure);

        try {
            $main->start();
        } finally {
            self::assertFileDoesNotExist($path);
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testEmptyQueueDoesNotCreateDaemonResources(): void
    {
        $path = $this->pidPath();
        new MainProcess(
            $this->createStub(StdoutLoggerInterface::class),
            new SwooleConfig(daemon: true, pidFile: $path),
        )->start();

        self::assertFileDoesNotExist($path);
        self::assertFileDoesNotExist($path . '.log');
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testForkedChildrenCannotReleaseTheSupervisorPidLock(): void
    {
        $path = $this->pidPath();
        $config = new SwooleConfig(pidFile: $path);
        $logger = $this->createStub(StdoutLoggerInterface::class);
        $main = new MainProcess($logger, $config);
        $main->addProcess(static function () use ($config, $path, $logger): void {
            $contender = new MainProcess($logger, $config);
            $contender->add(static function (): void {
                throw new RuntimeException('The child acquired the supervisor lock.');
            });
            try {
                $contender->start();
            } catch (SupervisorException $error) {
                file_put_contents($path . '.result', $error->getMessage());
            }
        });

        try {
            $main->start();
            self::assertStringContainsString('Cannot acquire PID file', file_get_contents($path . '.result'));
            self::assertFileDoesNotExist($path);
        } finally {
            unlink($path . '.result');
        }
    }

    /** @throws Throwable When fixture setup or the operation under test throws */
    public function testSupervisorRestoresSignalMaskAndDoesNotReapUnrelatedChildren(): void
    {
        pcntl_sigprocmask(SIG_BLOCK, [
            SIGUSR1,
        ], $originalMask);
        pcntl_sigprocmask(SIG_BLOCK, [
            SIGUSR1,
        ], $expectedMask);
        $unrelated = new Process(static function (
            Process $process,
        ): void {
            $process->exit(23);
        }, false, Process::PIPE_TYPE_NONE);
        $pid = $unrelated->start();
        self::assertIsInt($pid);
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $main->addProcess(static function (): void {
        });

        try {
            $main->start();
            pcntl_sigprocmask(SIG_BLOCK, [
                SIGUSR1,
            ], $actualMask);
            self::assertSame($expectedMask, $actualMask);
            self::assertSame($pid, pcntl_waitpid($pid, $status));
            self::assertSame(23, pcntl_wexitstatus($status));
        } finally {
            pcntl_sigprocmask(SIG_SETMASK, $originalMask);
        }
    }

    /** @throws Throwable When fixture setup or a supplied test callback fails */
    private function pidPath(): string
    {
        return sys_get_temp_dir() . '/sk-main-supervisor-' . bin2hex(random_bytes(8)) . '.pid';
    }
}
