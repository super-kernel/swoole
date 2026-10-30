<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use SuperKernel\Swoole\Exception\SupervisorException;
use Swoole\Process;

/**
 * Holds the live direct children, first child failure, shutdown request and signal blocking of one supervisor run.
 *
 * @internal
 */
final class SupervisorState
{
    /** @var array<int, Process> Live direct children, in mounting order. */
    public array $children = [];
    public ?SupervisorException $failure = null;
    public bool $stopping = false;
    public bool $signalsBlocked = false;
}
