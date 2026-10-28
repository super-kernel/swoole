<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Fixture;

use JsonException;
use Psr\Log\LoggerTrait;
use Stringable;
use SuperKernel\Contract\StdoutLoggerInterface;

use function file_put_contents;
use function json_encode;

use const FILE_APPEND;
use const JSON_THROW_ON_ERROR;

/** Emits inspectable records through stdout or a replacement destination in native process scenarios. */
final class StdoutLogger implements StdoutLoggerInterface
{
    use LoggerTrait;

    public function __construct(private readonly string $stream = 'php://stdout')
    {
    }

    /**
     * @param array<array-key, mixed> $context
     *
     * @throws JsonException When a test record cannot be encoded
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        file_put_contents($this->stream, json_encode([
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
    }
}
