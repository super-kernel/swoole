<?php

declare(strict_types=1);

namespace SuperKernel\Swoole\Command;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SuperKernel\Command\Attribute\Command as AttributeCommand;
use SuperKernel\Swoole\Event\MainProcessCreated;
use SuperKernel\Swoole\MainProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AttributeCommand(
    name: self::NAME,
    description: self::DESCRIPTION,
)]
final class SwooleStartCommand extends Command
{
    private const string NAME = 'swoole:start';
    private const string DESCRIPTION = 'Starts the supervised Swoole server and background tasks.';

    private MainProcess $mainProcess {
        /** @throws ContainerExceptionInterface When the main process or its dependencies cannot be resolved */
        get {
            if (!isset($this->mainProcess)) {
                $this->mainProcess = $this->container->get(MainProcess::class);
            }

            return $this->mainProcess;
        }
    }

    private EventDispatcherInterface $dispatcher {
        /** @throws ContainerExceptionInterface When the event dispatcher cannot be resolved */
        get {
            if (!isset($this->dispatcher)) {
                $this->dispatcher = $this->container->get(EventDispatcherInterface::class);
            }

            return $this->dispatcher;
        }
    }

    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct(self::NAME);
        $this->setDescription(self::DESCRIPTION);
    }

    /** @throws Throwable when configuration, registration or supervision fails; Console renders the failure */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->dispatcher->dispatch(new MainProcessCreated($this->mainProcess));

        $this->mainProcess->start();

        return self::SUCCESS;
    }
}
