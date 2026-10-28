<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Runtime;

use SuperKernel\Swoole\Config\SwooleConfigInterface;
use SuperKernel\Swoole\Exception\SupervisorException;
use Swoole\Coroutine;

use function ini_set;

/** @internal Applies process-wide options before MainProcess starts initialization or forks its children. */
final class RuntimeSettings
{
    /** @throws SupervisorException when a Swoole INI directive cannot be changed */
    public static function apply(SwooleConfigInterface $config): void
    {
        foreach ([
            'swoole.display_errors'              => (string) (int) $config->displayErrors(),
            'swoole.socket_buffer_size'          => (string) $config->socketBufferSize(),
            'swoole.enable_preemptive_scheduler' => (string) (int) $config->enablePreemptiveScheduler(),
        ] as $directive => $value) {
            if (false === ini_set($directive, $value)) {
                throw new SupervisorException('Unable to apply Swoole INI setting: ' . $directive . '.');
            }
        }

        // Updating INI alone does not refresh the socket default or replace earlier coroutine options.
        Coroutine::set([
            'display_errors'              => $config->displayErrors(),
            'socket_buffer_size'          => $config->socketBufferSize(),
            'enable_preemptive_scheduler' => $config->enablePreemptiveScheduler(),
        ]);
    }
}
