<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use SuperKernel\Config\Exception\ConfigurationNotFoundException;
use SuperKernel\Contract\ConfigInterface;
use SuperKernel\Swoole\Config\SwooleConfigInterface;
use SuperKernel\Swoole\Provider\SwooleConfigProvider;
use SuperKernel\Swoole\Tests\Fixture\SwooleConfig;

final class SwooleConfigProviderTest extends TestCase
{
    /** @return iterable<string, array{bool, ?string, bool, int, bool}> */
    public static function validSettings(): iterable
    {
        yield 'foreground without paths' => [
            false,
            null,
            true,
            8388608,
            false,
        ];
        yield 'foreground with PID file' => [
            false,
            '/tmp/supervisor.pid',
            false,
            4194304,
            true,
        ];
        yield 'daemon with PID file' => [
            true,
            '/tmp/supervisor.pid',
            true,
            16777216,
            false,
        ];
        yield 'smallest socket buffer' => [
            false,
            null,
            true,
            1,
            false,
        ];
        yield 'largest socket buffer' => [
            false,
            null,
            true,
            4294967295,
            false,
        ];
    }

    /** @return iterable<string, array{bool, ?string, int, string}> */
    public static function invalidSettings(): iterable
    {
        yield 'daemon without PID file' => [
            true,
            null,
            8388608,
            'Use an absolute PID file path; daemon mode requires one.',
        ];
        yield 'relative PID file' => [
            false,
            'supervisor.pid',
            8388608,
            'Use an absolute PID file path; daemon mode requires one.',
        ];
        foreach ([
            'zero'     => 0,
            'negative' => -1,
            'overflow' => 4294967296,
        ] as $name => $size) {
            yield $name . ' socket buffer' => [
                false,
                null,
                $size,
                'Use a socket buffer size between 1 and 4294967295 bytes.',
            ];
        }
    }

    /** @throws ContainerExceptionInterface when the test source cannot be resolved */
    #[DataProvider('validSettings')]
    public function testReturnsTheValidatedApplicationObject(
        bool    $daemon,
        ?string $pidFile,
        bool    $displayErrors,
        int     $socketBufferSize,
        bool    $enablePreemptiveScheduler,
    ): void
    {
        $source = new SwooleConfig(
            $daemon,
            $pidFile,
            $displayErrors,
            $socketBufferSize,
            $enablePreemptiveScheduler,
        );

        self::assertSame($source, new SwooleConfigProvider()($this->registry($source)));
    }

    /** @throws ContainerExceptionInterface when the test source cannot be resolved */
    #[DataProvider('invalidSettings')]
    public function testRejectsInvalidRuntimeSettings(
        bool    $daemon,
        ?string $pidFile,
        int     $socketBufferSize,
        string  $message,
    ): void
    {
        $config = $this->registry(new SwooleConfig($daemon, $pidFile, socketBufferSize: $socketBufferSize));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($message);

        new SwooleConfigProvider()($config);
    }

    /** @throws ContainerExceptionInterface the expected missing-declaration failure */
    public function testRejectsMissingConfiguration(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->expects(self::once())->method('get')->with(SwooleConfigInterface::class)->willReturn(null);
        $this->expectException(ConfigurationNotFoundException::class);
        $this->expectExceptionMessageIs(
            'No configuration source is registered for ' . SwooleConfigInterface::class . '.',
        );

        new SwooleConfigProvider()($config);
    }

    private function registry(SwooleConfigInterface $source): ConfigInterface
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->expects(self::once())->method('get')->with(SwooleConfigInterface::class)->willReturn($source);

        return $config;
    }
}
