<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Event;

use SuperKernel\Contract\EventInterface;
use SuperKernel\Swoole\MainProcess;

/**
 * Synchronous registration phase before the main process starts.
 *
 * Listeners mount initialization with mainProcess->add() and child runtimes with mainProcess->addProcess().
 * Each component owns its runtime registration; MainProcess owns daemon/PID settings and child supervision.
 * No mounted callback has run and no process has been started by the runtime yet.
 */
final readonly class MainProcessCreated implements EventInterface
{
    public function __construct(public MainProcess $mainProcess)
    {
    }
}
