<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Config;

/**
 * Supplies main supervisor and Swoole runtime settings through a validated DI interface entry.
 *
 * Applications declare an implementation with the Configuration Attribute. SwooleConfigProvider resolves it
 * through ConfigInterface, validates it, and returns that same object for interface injection.
 * Configuration resolution does not change process-wide runtime settings.
 * Non-empty MainProcess startup applies the runtime settings before initialization callbacks or child creation.
 */
interface SwooleConfigInterface
{
    /** Returns whether MainProcess enters daemon mode before initializing and starting mounted child processes. */
    public function daemon(): bool;

    /** Returns MainProcess's absolute PID-file path; it holds the final supervisor PID after entering daemon mode. */
    public function pidFile(): ?string;

    /** @return bool Whether Swoole displays its own errors; independent of PHP display_errors and the logger */
    public function displayErrors(): bool;

    /** @return positive-int Default Swoole socket buffer capacity in bytes, at most 4294967295 */
    public function socketBufferSize(): int;

    /** @return bool Whether to enable preemptive scheduling when child runtimes activate their coroutines */
    public function enablePreemptiveScheduler(): bool;
}
