<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Server;

enum Mode: int
{
	case SWOOLE_BASE = 1;

	case SWOOLE_PROCESS = 2;

	case SWOOLE_THREAD = 3;
}