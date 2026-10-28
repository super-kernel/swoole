<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Integration;

use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\RandomException;
use SuperKernel\Swoole\Tests\Fixture\MainProcessScenario;
use Throwable;

use function array_filter;
use function array_values;
use function bin2hex;
use function dirname;
use function fclose;
use function file;
use function file_get_contents;
use function glob;
use function hrtime;
use function in_array;
use function is_file;
use function json_decode;
use function mkdir;
use function posix_kill;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function random_bytes;
use function rmdir;
use function str_starts_with;
use function substr;
use function sys_get_temp_dir;
use function unlink;
use function usleep;
use function var_export;

use const FILE_IGNORE_NEW_LINES;
use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;
use const SIGINT;
use const SIGKILL;
use const SIGTERM;

final class MainProcessTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        foreach ([
            'term',
            'int',
            'group-int',
            'daemon',
            'daemon-file-logger',
            'daemon-child-failure',
            'daemon-startup-failure',
            'natural',
            'child-failure',
            'startup-failure',
        ] as $case) {
            yield $case => [
                $case,
            ];
        }
    }

    /**
     * @throws JsonException   When fixture events or logger records cannot be decoded
     * @throws RandomException When a temporary directory name cannot be generated
     * @throws Throwable       When fixture setup or the operation under test throws
     */
    #[DataProvider('scenarios')]
    public function testSupervisorPidShutdownAndReaping(string $scenario): void
    {
        $directory = sys_get_temp_dir() . '/sk-main-' . bin2hex(random_bytes(8));
        $behavior = str_starts_with($scenario, 'daemon-') ? substr($scenario, 7) : $scenario;
        mkdir($directory);
        $bootstrap = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '$tests = new Composer\\Autoload\\ClassLoader();'
            . '$tests->addPsr4("SuperKernel\\\\Swoole\\\\Tests\\\\", '
            . var_export(dirname(__DIR__), true) . '); $tests->register(true); posix_setsid();'
            . MainProcessScenario::class . '::run(' . var_export($scenario, true) . ', '
            . var_export($directory, true) . ');';
        $process = proc_open([
            PHP_BINARY,
            '-r',
            $bootstrap,
        ], [
            0 => [
                'pipe',
                'r',
            ],
            1 => [
                'file',
                $directory . '/stdout',
                'a',
            ],
            2 => [
                'file',
                $directory . '/stderr',
                'a',
            ],
        ], $pipes);
        fclose($pipes[0]);
        $launcher = proc_get_status($process)['pid'];
        $supervisor = null;
        $signalled = false;
        try {
            $deadline = hrtime(true) + 6_000_000_000;
            do {
                $events = $this->readEvents($directory);
                $supervisor = $this->events($events, 'supervisor')[0]['pid'] ?? null;
                if (!$signalled && null !== $supervisor && [] !== $this->events($events, 'ready')
                    && in_array($scenario, [
                        'term',
                        'int',
                        'group-int',
                        'daemon',
                        'daemon-file-logger',
                    ], true)) {
                    self::assertTrue(posix_kill(
                        'group-int' === $scenario ? -$supervisor : $supervisor,
                        in_array($scenario, [
                            'int',
                            'group-int',
                        ], true) ? SIGINT : SIGTERM,
                    ));
                    $signalled = true;
                }
                if ([] !== $this->events($events, 'reaped')) {
                    break;
                }
                usleep(10000);
            } while (hrtime(true) < $deadline);

            $errors = file_get_contents($directory . '/stderr');
            $logs = $this->readLogs($directory . ('file-logger' === $behavior ? '/custom.log' : '/stdout'));
            self::assertCount(1, $this->events($events, 'reaped'), $errors);
            self::assertSame(-1, $this->events($events, 'reaped')[0]['remainingChild']);
            self::assertFalse($this->events($events, 'reaped')[0]['pidFileExists']);
            self::assertFileDoesNotExist($directory . '/main.pid');
            self::assertCount(1, $this->events($events, 'supervisor'));
            self::assertSame($supervisor, $this->events($events, 'supervisor')[0]['published']);
            if (str_starts_with($scenario, 'daemon')) {
                self::assertNotSame($launcher, $supervisor);
                self::assertSame([
                    'level'   => 'info',
                    'message' => 'supervisor output',
                    'context' => [],
                ], $logs[0]);
                if ('file-logger' === $behavior) {
                    self::assertSame('', file_get_contents($directory . '/stdout'));
                }
            } else {
                self::assertSame($launcher, $supervisor);
            }
            self::assertCount(2, $this->events($events, 'started'));
            foreach ($this->events($events, 'started') as $child) {
                self::assertNotSame($supervisor, $child['pid']);
                self::assertSame($supervisor, $child['parent']);
                self::assertSame($supervisor, $child['published']);
            }
            self::assertFileDoesNotExist($directory . '/main.pid.log');
            self::assertSame('', $errors);
            self::assertCount(match ($behavior) {
                'group-int'     => 0,
                'child-failure' => 1,
                default         => 2,
            }, $this->events($events, 'stopped'));
            foreach ($this->events($events, 'stopped') as $child) {
                self::assertSame($supervisor, $child['published']);
                self::assertSame('natural' === $scenario ? null : SIGTERM, $child['signal']);
                self::assertLessThan($this->events($events, 'reaped')[0]['time'], $child['time']);
            }
            if (in_array($behavior, [
                'child-failure',
                'startup-failure',
            ], true)) {
                self::assertSame([], $this->events($events, 'returned'));
                self::assertCount(1, $this->events($events, 'failed'));
                $failure = $this->events($events, 'failed')[0];
                self::assertSame('child-failure' === $behavior, $failure['supervisorException']);
                if ('child-failure' === $behavior) {
                    self::assertStringContainsString('exit code 1', $failure['message']);
                    self::assertContains([
                        'level'   => 'error',
                        'message' => 'Mounted process failed: Intentional mounted child failure.',
                        'context' => [],
                    ], $logs);
                } else {
                    self::assertSame('Intentional initialization failure.', $failure['message']);
                }
                if (str_starts_with($scenario, 'daemon')) {
                    self::assertContains([
                        'level'   => 'error',
                        'message' => 'Main process failed: ' . $failure['message'],
                        'context' => [],
                    ], $logs);
                }
            } else {
                self::assertSame([], $this->events($events, 'failed'));
                self::assertCount(1, $this->events($events, 'returned'));
                self::assertSame('', $errors);
            }
        } finally {
            if (proc_get_status($process)['running']) {
                posix_kill(-$launcher, SIGKILL);
            }
            proc_close($process);
            if (is_file($directory . '/main.pid') && null !== $supervisor && 1 < $supervisor) {
                posix_kill(-$supervisor, SIGKILL);
            }
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /**
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     *
     * @throws JsonException When a fixture log record is invalid
     */
    private function readLogs(string $path): array
    {
        $logs = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $logs[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }

        return $logs;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws Throwable When fixture setup or a supplied test callback fails
     */
    private function readEvents(string $directory): array
    {
        if (!is_file($directory . '/events.jsonl')) {
            return [];
        }
        $events = [];
        foreach (file($directory . '/events.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
            $events[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private function events(array $events, string $event): array
    {
        return array_values(array_filter($events, static fn(array $entry): bool => $event === $entry['event']));
    }
}
