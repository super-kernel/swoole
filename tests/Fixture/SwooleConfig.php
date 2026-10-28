<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Fixture;

use SuperKernel\Swoole\Config\SwooleConfigInterface;

final readonly class SwooleConfig implements SwooleConfigInterface
{
    public function __construct(
        private bool    $daemon = false,
        private ?string $pidFile = null,
        private bool    $displayErrors = true,
        private int     $socketBufferSize = 8388608,
        private bool    $enablePreemptiveScheduler = false,
    )
    {
    }

    public function daemon(): bool
    {
        return $this->daemon;
    }

    public function pidFile(): ?string
    {
        return $this->pidFile;
    }

    public function displayErrors(): bool
    {
        return $this->displayErrors;
    }

    public function socketBufferSize(): int
    {
        return $this->socketBufferSize;
    }

    public function enablePreemptiveScheduler(): bool
    {
        return $this->enablePreemptiveScheduler;
    }
}
