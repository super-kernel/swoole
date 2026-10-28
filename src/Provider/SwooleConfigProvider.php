<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Provider;

use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;
use SuperKernel\Attribute\FactoryProvider;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Swoole\Config\SwooleConfigInterface;

use function sprintf;
use function str_starts_with;

#[FactoryProvider(SwooleConfigInterface::class)]
final readonly class SwooleConfigProvider
{
    /**
     * @throws ContainerExceptionInterface    when the application's Swoole configuration cannot be resolved
     * @throws ConfigurationNotFoundException when no Swoole configuration source is declared
     * @throws InvalidArgumentException       for invalid PID paths or socket buffer sizes outside 1..4294967295 bytes
     */
    public function __invoke(ConfigInterface $config): SwooleConfigInterface
    {
        $configuration = $config->get(SwooleConfigInterface::class)
            ?? throw new ConfigurationNotFoundException(sprintf(
                'No configuration source is registered for %s.',
                SwooleConfigInterface::class,
            ));
        $daemon = $configuration->daemon();
        $pidFile = $configuration->pidFile();

        if (($daemon && null === $pidFile) || (null !== $pidFile && !str_starts_with($pidFile, '/'))) {
            throw new InvalidArgumentException('Use an absolute PID file path; daemon mode requires one.');
        }

        $socketBufferSize = $configuration->socketBufferSize();
        if (1 > $socketBufferSize || 4294967295 < $socketBufferSize) {
            throw new InvalidArgumentException('Use a socket buffer size between 1 and 4294967295 bytes.');
        }

        return $configuration;
    }
}
