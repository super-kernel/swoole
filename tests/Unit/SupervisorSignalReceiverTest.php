<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SuperKernel\Swoole\Constants\SupervisorSignal;
use SuperKernel\Swoole\Process\SupervisorSignalReceiver;

use function function_exists;
use function getmypid;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_signal_get_handler;
use function pcntl_sigprocmask;
use function posix_kill;
use function sort;

use const SIG_BLOCK;
use const SIG_DFL;
use const SIG_SETMASK;
use const SIGCHLD;
use const SIGTERM;
use const SIGUSR1;

final class SupervisorSignalReceiverTest extends TestCase
{
    /** @var list<int> */
    private array $signalMask = [];

    /** @return iterable<string, array{bool}> */
    public static function receivers(): iterable
    {
        yield 'polling' => [
            false,
        ];

        if (function_exists('pcntl_sigwaitinfo') && function_exists('pcntl_sigtimedwait')) {
            yield 'waiting' => [
                true,
            ];
        }
    }

    protected function setUp(): void
    {
        self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, SupervisorSignal::numbers(), $this->signalMask));
    }

    protected function tearDown(): void
    {
        pcntl_sigprocmask(SIG_SETMASK, $this->signalMask);
        pcntl_signal_dispatch();
    }

    #[DataProvider('receivers')]
    public function testPollFindsNothingWhenNoSignalIsPending(bool $canWait): void
    {
        $receiver = new SupervisorSignalReceiver($canWait);
        $receiver->install();

        try {
            self::assertFalse($receiver->poll());
        } finally {
            $receiver->uninstall();
        }
    }

    #[DataProvider('receivers')]
    public function testPollReceivesEachPendingSignalOnce(bool $canWait): void
    {
        $receiver = new SupervisorSignalReceiver($canWait);
        $receiver->install();

        try {
            self::assertTrue(posix_kill(getmypid(), SIGTERM));
            self::assertTrue(posix_kill(getmypid(), SIGCHLD));
            $received = [];

            while (false !== ($signal = $receiver->poll())) {
                $received[] = $signal;
            }

            $expected = [
                SIGCHLD,
                SIGTERM,
            ];
            sort($received);
            sort($expected);
            self::assertSame($expected, $received);
        } finally {
            $receiver->uninstall();
        }
    }

    #[DataProvider('receivers')]
    public function testWaitReturnsAPendingSignal(bool $canWait): void
    {
        $receiver = new SupervisorSignalReceiver($canWait);
        $receiver->install();

        try {
            self::assertTrue(posix_kill(getmypid(), SIGTERM));
            self::assertSame(SIGTERM, $receiver->wait());
        } finally {
            $receiver->uninstall();
        }
    }

    #[DataProvider('receivers')]
    public function testLeavesOtherSignalsAndTheMaskAlone(bool $canWait): void
    {
        $delivered = false;
        pcntl_signal(SIGUSR1, static function () use (&$delivered): void {
            $delivered = true;
        });
        $receiver = new SupervisorSignalReceiver($canWait);
        $receiver->install();

        try {
            self::assertTrue(posix_kill(getmypid(), SIGUSR1));
            self::assertFalse($receiver->poll());
            pcntl_signal_dispatch();
            self::assertTrue($delivered);

            self::assertTrue(pcntl_sigprocmask(SIG_BLOCK, SupervisorSignal::numbers(), $mask));
            foreach (SupervisorSignal::numbers() as $signal) {
                self::assertContains($signal, $mask);
            }
        } finally {
            $receiver->uninstall();
            pcntl_signal(SIGUSR1, SIG_DFL);
        }
    }

    public function testUninstallRestoresTheReplacedHandlers(): void
    {
        $handler = static function (): void {
        };
        pcntl_signal(SIGTERM, $handler);
        $receiver = new SupervisorSignalReceiver(false);
        $receiver->install();
        self::assertNotSame($handler, pcntl_signal_get_handler(SIGTERM));

        $receiver->uninstall();

        self::assertSame($handler, pcntl_signal_get_handler(SIGTERM));
        pcntl_signal(SIGTERM, SIG_DFL);
    }

    public function testInstallLeavesTheHandlersAloneWhenPhpCanWait(): void
    {
        $receiver = new SupervisorSignalReceiver(true);
        $receiver->install();

        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGTERM));
        $receiver->uninstall();
    }
}
