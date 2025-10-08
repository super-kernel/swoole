<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Setting;

/**
 * *Set the level of `Server` error log printing, ranging from `0-6`. Log information below the `log_level` set will
 * not be thrown.**【Default value: `SWOOLE_LOG_INFO`】
 *
 * Corresponding level constants refer to [Log Level](/consts?id=Log Level)
 *
 *  **Note**
 *
 * !> `SWOOLE_LOG_DEBUG` and `SWOOLE_LOG_TRACE` are only available when compiling with
 * [--enable-debug-log](/environment?id=debug parameters) and [--enable-trace-log](/environment?id=debug parameters)
 * versions; When `daemonize` is enabled, the underlying system will write all screen output in the program to
 * [log_file](/server/setting?id=log_file), and this part of content is not controlled by `log_level`.
 */
enum LogLevel: int
{
	/**
	 * @see SWOOLE_LOG_DEBUG
	 */
	case SWOOLE_LOG_DEBUG = 0;

	/**
	 * @see SWOOLE_LOG_TRACE
	 */
	case SWOOLE_LOG_TRACE = 1;

	/**
	 * @see SWOOLE_LOG_INFO
	 */
	case SWOOLE_LOG_INFO = 2;

	/**
	 * @see SWOOLE_LOG_NOTICE
	 */
	case SWOOLE_LOG_NOTICE = 3;

	/**
	 * @see SWOOLE_LOG_WARNING
	 */
	case SWOOLE_LOG_WARNING = 4;

	/**
	 * @see SWOOLE_LOG_ERROR
	 */
	case SWOOLE_LOG_ERROR = 5;

	/**
	 * @see SWOOLE_LOG_NONE
	 */
	case SWOOLE_LOG_NONE = 6;
}