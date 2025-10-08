<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Setting;

/**
 * Set the function scope for `Coroutine Hook`. [Default value: Do not hook]
 *
 * Swoole version `v4.5+` or <a href="https://github.com/swoole/swoole-src/tree/v4.4.x">4.4LTS</a> is available, for
 * more.
 *
 * details please refer to [Coroutine](/runtime).
 *
 * SWOOLE_HOOK_ALL configuration is deprecated, see:
 *
 * ```
 *      if (class_exists(Swoole\Coroutine\Curl\Exception::class)) {
 *          define('SWOOLE_HOOK_ALL', 0x7FFFFFFF ^ SWOOLE_HOOK_CURL);
 *      } else {
 *          define('SWOOLE_HOOK_ALL', 0x7FFFFFFF ^ SWOOLE_HOOK_NATIVE_CURL);
 *      }
 * ```
 */
enum HookFlags: int
{
	/**
	 * @see SWOOLE_HOOK_TCP
	 */
	case SWOOLE_HOOK_TCP = 2;

	/**
	 * @see SWOOLE_HOOK_UNIX
	 */
	case SWOOLE_HOOK_UNIX = 8;

	/**
	 * @see SWOOLE_HOOK_UDP
	 */
	case SWOOLE_HOOK_UDP = 4;

	/**
	 * @see SWOOLE_HOOK_UDG
	 */
	case SWOOLE_HOOK_UDG = 16;

	/**
	 * @see SWOOLE_HOOK_SSL
	 */
	case SWOOLE_HOOK_SSL = 32;

	/**
	 * @see SWOOLE_HOOK_TLS
	 */
	case SWOOLE_HOOK_TLS = 64;

	/**
	 * @see SWOOLE_HOOK_SLEEP
	 */
	case SWOOLE_HOOK_SLEEP = 512;

	/**
	 * @see SWOOLE_HOOK_FILE
	 */
	case SWOOLE_HOOK_FILE = 256;

	/**
	 * @see SWOOLE_HOOK_STREAM_FUNCTION
	 */
	case SWOOLE_HOOK_STREAM_FUNCTION = 128;

	/**
	 * @see SWOOLE_HOOK_BLOCKING_FUNCTION
	 */
	case SWOOLE_HOOK_BLOCKING_FUNCTION = 8192;

	/**
	 * @see SWOOLE_HOOK_PROC
	 */
	case SWOOLE_HOOK_PROC = 1024;

	/**
	 * @see SWOOLE_HOOK_CURL
	 */
	case SWOOLE_HOOK_CURL = 2048;

	/**
	 * @see SWOOLE_HOOK_NATIVE_CURL
	 */
	case SWOOLE_HOOK_NATIVE_CURL = 4096;

	/**
	 * @see SWOOLE_HOOK_SOCKETS
	 */
	case SWOOLE_HOOK_SOCKETS = 16384;

	/**
	 * @see SWOOLE_HOOK_STDIO
	 */
	case SWOOLE_HOOK_STDIO = 32768;

	/**
	 * @see SWOOLE_HOOK_PDO_PGSQL
	 */
	case SWOOLE_HOOK_PDO_PGSQL = 65536;

	/**
	 * @see SWOOLE_HOOK_PDO_ODBC
	 */
	case SWOOLE_HOOK_PDO_ODBC = 131072;

	/**
	 * @see SWOOLE_HOOK_PDO_ORACLE
	 */
	case SWOOLE_HOOK_PDO_ORACLE = 262144;

	/**
	 * @see SWOOLE_HOOK_PDO_SQLITE
	 */
	case SWOOLE_HOOK_PDO_SQLITE = 524288;

	/**
	 * Use this option when Swoole is installed without the option `--enable-swoole-curl`.otherwise, use
	 * `HookFlags::SWOOLE_HOOK_NATIVE_ALL`.
	 */
	case SWOOLE_HOOK_ALL = 2147481599;

	/**
	 * Use this option when Swoole is installed with option `--enable-swoole-curl` included.otherwise, use
	 * `HookFlags::SWOOLE_HOOK_ALL`.
	 */
	case SWOOLE_HOOK_NATIVE_ALL = 2147479551;
}