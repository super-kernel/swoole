<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Swoole\Exception\SupervisorException;
use SuperKernel\Swoole\MainProcess;
use SuperKernel\Swoole\Tests\Fixture\SwooleConfig;
use Swoole\Coroutine;
use Throwable;

use function file_get_contents;
use function file_put_contents;
use function ini_get;
use function ini_set;
use function json_encode;
use function Swoole\Coroutine\run;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SwooleRuntimeSettingsTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function schedulerSettings(): iterable
    {
        yield 'enable' => [
            true,
        ];
        yield 'disable' => [
            false,
        ];
    }

    /** @throws Throwable if startup, child execution or cleanup fails */
    #[DataProvider('schedulerSettings')]
    public function testAppliesSettingsBeforeInitializationAndInheritsThemInChildren(bool $enabled): void
    {
        ini_set('swoole.enable_preemptive_scheduler', (string) (int) !$enabled);
        Coroutine::set([
            'hook_flags'                  => 0,
            'display_errors'              => true,
            'socket_buffer_size'          => 131072,
            'enable_preemptive_scheduler' => !$enabled,
        ]);
        $before = self::settings();
        $config = new SwooleConfig(
            displayErrors: false,
            socketBufferSize: 4194304,
            enablePreemptiveScheduler: $enabled,
        );
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), $config);
        $observed = null;
        $main->add(static function () use (&$observed): void {
            $observed = self::settings();
        });
        $path = tempnam(sys_get_temp_dir(), 'sk-swoole-settings-');
        self::assertNotFalse($path);

        try {
            $main->addProcess(static function () use ($path): void {
                file_put_contents($path, json_encode(self::settings()));
            });
            self::assertSame($before, self::settings());

            $main->start();

            $expected = [
                'display_errors'              => '0',
                'socket_buffer_size'          => '4194304',
                'enable_preemptive_scheduler' => (string) (int) $enabled,
                'options'                     => [
                    'hook_flags'                  => 0,
                    'display_errors'              => false,
                    'socket_buffer_size'          => 4194304,
                    'enable_preemptive_scheduler' => $enabled,
                ],
                'coroutine_id'   => -1,
                'event_num'      => 0,
                'aio_worker_num' => 0,
            ];
            self::assertSame($expected, $observed);
            self::assertSame(json_encode($expected), file_get_contents($path));
            self::assertSame($expected, self::settings());
        } finally {
            unlink($path);
        }
    }

    /** @throws Throwable if empty-runtime startup fails */
    public function testEmptyRuntimeLeavesSettingsUnchanged(): void
    {
        $before = self::settings();
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig(
            displayErrors: false,
            socketBufferSize: 4194304,
            enablePreemptiveScheduler: true,
        ));

        $main->start();

        self::assertSame($before, self::settings());
    }

    public function testRejectsInitializationInsideAnActiveCoroutineBeforeChangingSettings(): void
    {
        $failure = null;
        $ran = false;
        $main = new MainProcess($this->createStub(StdoutLoggerInterface::class), new SwooleConfig());
        $main->add(static function () use (&$ran): void {
            $ran = true;
        });

        run(static function () use ($main, &$failure): void {
            $before = self::settings();
            try {
                $main->start();
            } catch (SupervisorException $error) {
                $failure = $error;
            }
            self::assertSame($before, self::settings());
        });

        self::assertInstanceOf(SupervisorException::class, $failure);
        self::assertFalse($ran);
    }

    /**
     * @return array{
     *     display_errors: string|false,
     *     socket_buffer_size: string|false,
     *     enable_preemptive_scheduler: string|false,
     *     options: array<string, mixed>|null,
     *     coroutine_id: int,
     *     event_num: int,
     *     aio_worker_num: int,
     * }
     */
    private static function settings(): array
    {
        $stats = Coroutine::stats();

        return [
            'display_errors'              => ini_get('swoole.display_errors'),
            'socket_buffer_size'          => ini_get('swoole.socket_buffer_size'),
            'enable_preemptive_scheduler' => ini_get('swoole.enable_preemptive_scheduler'),
            'options'                     => Coroutine::getOptions(),
            'coroutine_id'                => Coroutine::getCid(),
            'event_num'                   => $stats['event_num'],
            'aio_worker_num'              => $stats['aio_worker_num'],
        ];
    }
}
