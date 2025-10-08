<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Server;

enum SocketType: int
{
	case SWOOLE_SOCK_TCP = 1;

	case SWOOLE_SOCK_UDP = 2;

	case SWOOLE_SOCK_TCP6 = 3;

	case SWOOLE_SOCK_UDP6 = 4;

	case SWOOLE_SOCK_UNIX_STREAM = 5;

	case SWOOLE_SOCK_UNIX_DGRAM = 6;
}