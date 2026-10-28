<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Process;

use SuperKernel\Swoole\Exception\SupervisorException;

use function fclose;
use function fflush;
use function file_get_contents;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function getmypid;
use function is_resource;
use function trim;
use function unlink;

use const LOCK_EX;
use const LOCK_NB;

/** @internal MainProcess holds this lock. Forked children only close their local reference. */
final class PidFile
{
    /** @var resource|null */
    private mixed $handle = null;
    private int $owner = 0;

    /** @throws SupervisorException if the PID file cannot be opened or exclusively locked */
    public function __construct(private readonly string $path)
    {
        $handle = @fopen($path, 'c+');
        if (false === $handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new SupervisorException('Cannot acquire PID file ' . $path . '.');
        }
        $this->handle = $handle;
    }

    /** @throws SupervisorException if the supervisor PID cannot be written and flushed */
    public function publish(): void
    {
        $this->owner = getmypid();
        if (!is_resource($this->handle) || !ftruncate($this->handle, 0)
            || false === fwrite($this->handle, $this->owner . "\n") || !fflush($this->handle)) {
            throw new SupervisorException('Unable to publish the supervisor PID.');
        }
    }

    public function close(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        if ($this->owner === getmypid() && trim((string) @file_get_contents($this->path)) === (string) $this->owner) {
            unlink($this->path);
        }
        // Never LOCK_UN an inherited open-file description in a business child.
        fclose($this->handle);
        $this->handle = null;
    }
}
