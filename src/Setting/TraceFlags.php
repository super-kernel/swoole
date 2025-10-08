<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Setting;

/**
 * Setting the tags for trace logs, only print partial trace logs. `trace_flags` supports setting multiple trace items
 * using the `|` OR operator, which only takes effect if `--enable-trace-log` is turned on during compilation.
 */
enum TraceFlags: int
{
	/**
	 * @see SWOOLE_TRACE_SERVER
	 */
	case SWOOLE_TRACE_SERVER = 2;


	/**
	 * @see SWOOLE_TRACE_CLIENT
	 */
	case SWOOLE_TRACE_CLIENT = 4;

	/**
	 * @see SWOOLE_TRACE_BUFFER
	 */
	case SWOOLE_TRACE_BUFFER = 8;

	/**
	 * @see SWOOLE_TRACE_CONN
	 */
	case SWOOLE_TRACE_CONN = 16;

	/**
	 * @see SWOOLE_TRACE_EVENT
	 */
	case SWOOLE_TRACE_EVENT = 32;

	/**
	 * @see SWOOLE_TRACE_WORKER
	 */
	case SWOOLE_TRACE_WORKER = 64;

	/**
	 * @see SWOOLE_TRACE_REACTOR
	 */
	case SWOOLE_TRACE_REACTOR = 256;

	/**
	 * @see SWOOLE_TRACE_PHP
	 */
	case SWOOLE_TRACE_PHP = 512;

	/**
	 * @see SWOOLE_TRACE_HTTP2
	 */
	case SWOOLE_TRACE_HTTP2 = 2048;

	/**
	 * @see SWOOLE_TRACE_EOF_PROTOCOL
	 */
	case SWOOLE_TRACE_EOF_PROTOCOL = 4096;

	/**
	 * @see SWOOLE_TRACE_LENGTH_PROTOCOL
	 */
	case SWOOLE_TRACE_LENGTH_PROTOCOL = 8192;

	/**
	 * @see SWOOLE_TRACE_CLOSE
	 */
	case SWOOLE_TRACE_CLOSE = 16384;

	/**
	 * @see SWOOLE_TRACE_HTTP_CLIENT
	 */
	case SWOOLE_TRACE_HTTP_CLIENT = 262144;

	/**
	 * @see SWOOLE_TRACE_COROUTINE
	 */
	case SWOOLE_TRACE_COROUTINE = 33554432;

	/**
	 * @see SWOOLE_TRACE_REDIS_CLIENT
	 */
	case SWOOLE_TRACE_REDIS_CLIENT = 65536;

	/**
	 * @see SWOOLE_TRACE_MYSQL_CLIENT
	 */
	case SWOOLE_TRACE_MYSQL_CLIENT = 131072;

	/**
	 * @see SWOOLE_TRACE_AIO
	 */
	case SWOOLE_TRACE_AIO = 524288;

	/**
	 * @see SWOOLE_TRACE_ALL
	 */
	case SWOOLE_TRACE_ALL = 9223372036854775807;
}