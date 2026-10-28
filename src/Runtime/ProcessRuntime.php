<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Runtime;

use Psr\EventDispatcher\EventDispatcherInterface;
use SuperKernel\Contract\StdoutLoggerInterface;
use SuperKernel\Swoole\Config\SwooleConfigInterface;
use SuperKernel\Swoole\Event\MainProcessCreated;
use SuperKernel\Swoole\MainProcess;
use Throwable;

final readonly class ProcessRuntime
{
    public function __construct(private EventDispatcherInterface $dispatcher, private StdoutLoggerInterface $logger)
    {
    }

    /**
     * Dispatch registration, then run the callbacks mounted by listeners on the main process.
     *
     * @throws Throwable when a registration listener, process registration or startup/execution fails
     */
    public function start(SwooleConfigInterface $config): void
    {
        $main = new MainProcess($this->logger, $config);
        $this->dispatcher->dispatch(new MainProcessCreated($main));
        $main->start();
    }
}
