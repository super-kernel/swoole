<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Constants;

use function array_column;

use const SIGCHLD;
use const SIGINT;
use const SIGTERM;

/**
 * Lists the signals that MainProcess blocks during startup and consumes synchronously, in handler-reset order.
 *
 * @internal
 */
enum SupervisorSignal: int
{
    // PHP accepts global constants in case values, but PhpStorm rejects them; class constants carry the numbers.
    private const int TERMINATE = SIGTERM;
    private const int INTERRUPT = SIGINT;
    private const int CHILD_STATUS = SIGCHLD;

    case Terminate = self::TERMINATE;
    case Interrupt = self::INTERRUPT;
    case ChildStatus = self::CHILD_STATUS;

    /**
     * @return list<int> native signal numbers in case declaration order
     */
    public static function numbers(): array
    {
        return array_column(self::cases(), 'value');
    }
}
