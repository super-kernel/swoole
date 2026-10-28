<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Fixture;

use RuntimeException;
use SuperKernel\Swoole\Exception\SupervisorException;
use SuperKernel\Swoole\MainProcess;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function hrtime;
use function is_file;
use function json_encode;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_waitpid;
use function posix_getppid;
use function str_starts_with;
use function substr;
use function usleep;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const SIGTERM;
use const WNOHANG;

final class MainProcessScenario
{
    private static string $directory;

    /** @throws Throwable When fixture setup or a supplied test callback fails */
    public static function run(string $scenario, string $directory): void
    {
        self::$directory = $directory;
        self::record('launcher');
        $daemon = str_starts_with($scenario, 'daemon');
        $scenario = str_starts_with($scenario, 'daemon-') ? substr($scenario, 7) : $scenario;
        $logger = new StdoutLogger('file-logger' === $scenario ? $directory . '/custom.log' : 'php://stdout');
        $main = new MainProcess($logger, new SwooleConfig(
            daemon: $daemon,
            pidFile: $directory . '/main.pid',
        ));
        $main->add(static function () use ($daemon, $logger): void {
            self::record('supervisor', [
                'published' => self::publishedPid(),
            ]);
            if ($daemon) {
                $logger->info('supervisor output');
            }
        });
        foreach ([
            0,
            1,
        ] as $index) {
            $main->addProcess(static function () use ($index, $scenario): void {
                $signal = null;
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM, static function (int $received) use (&$signal): void {
                    $signal = $received;
                });
                self::record('started', [
                    'index'     => $index,
                    'published' => self::publishedPid(),
                ]);
                file_put_contents(self::$directory . '/ready-' . $index, 'ready');
                self::awaitChildren();
                if ('child-failure' === $scenario && 0 === $index) {
                    throw new RuntimeException('Intentional mounted child failure.');
                }
                $deadline = hrtime(true) + 3_000_000_000;
                while ('natural' !== $scenario && null === $signal && hrtime(true) < $deadline) {
                    usleep(1000);
                }
                if ('natural' !== $scenario && null === $signal) {
                    throw new RuntimeException('Mounted child did not receive shutdown.');
                }
                // Both children must finish their cleanup before the supervisor removes its PID file.
                usleep(40000 * ($index + 1));
                self::record('stopped', [
                    'index'     => $index,
                    'signal'    => $signal,
                    'published' => self::publishedPid(),
                ]);
            });
        }
        $main->add(static function () use ($scenario): void {
            self::awaitChildren();
            self::record('ready');
            if ('group-int' === $scenario) {
                // Let Ctrl-C reach the whole foreground group before MainProcess enters its wait loop.
                usleep(100000);
            }
            if ('startup-failure' === $scenario) {
                throw new RuntimeException('Intentional initialization failure.');
            }
        });
        try {
            $main->start();
            self::record('returned');
        } catch (RuntimeException $error) {
            self::record('failed', [
                'message'             => $error->getMessage(),
                'supervisorException' => $error instanceof SupervisorException,
            ]);
        }
        self::record('reaped', [
            'remainingChild' => pcntl_waitpid(-1, $status, WNOHANG),
            'pidFileExists'  => is_file($directory . '/main.pid'),
        ]);
    }

    private static function awaitChildren(): void
    {
        $deadline = hrtime(true) + 2_000_000_000;
        while (!is_file(self::$directory . '/ready-0') || !is_file(self::$directory . '/ready-1')) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Timed out waiting for both mounted children.');
            }
            usleep(1000);
        }
    }

    private static function publishedPid(): int
    {
        return (int) file_get_contents(self::$directory . '/main.pid');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    private static function record(string $event, array $data = []): void
    {
        file_put_contents(self::$directory . '/events.jsonl', json_encode([
            'event'  => $event,
            'pid'    => getmypid(),
            'parent' => posix_getppid(),
            'time'   => hrtime(true),
            ...$data,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }
}
