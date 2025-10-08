<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Setting;

/**
 * *Set `Server` log rotation** [Default value: `SWOOLE_LOG_ROTATION_SINGLE`].
 */
enum LogRotation: int
{
	/**
	 * Not enabled
	 */
	case SWOOLE_LOG_ROTATION_SINGLE = 0;

	/**
	 * Monthly
	 */
	case SWOOLE_LOG_ROTATION_MONTHLY = 1;

	/**
	 * Daily
	 */
	case SWOOLE_LOG_ROTATION_DAILY = 2;

	/**
	 * Hourly
	 */
	case SWOOLE_LOG_ROTATION_HOURLY = 3;

	/**
	 * Every minute
	 */
	case SWOOLE_LOG_ROTATION_EVERY_MINUTE = 4;
}