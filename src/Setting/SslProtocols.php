<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Setting;

/**
 * **Set the protocol for OpenSSL tunnel encryption.** [Default value: `0`, which supports all protocols],
 *  supported types refer to [SSL Protocol](/consts?id=ssl-protocols)
 *
 *  Available in Swoole version >= `v4.5.4`
 *
 *  ```
 *   $server->set(array(
 *       'ssl_protocols' => 0,
 *   ));
 *  ```
 */
enum SslProtocols: int
{
	/**
	 * @see SWOOLE_SSL_TLSv1
	 */
	case SWOOLE_SSL_TLSv1 = 8;

	/**
	 * @see SWOOLE_SSL_TLSv1_1
	 */
	case SWOOLE_SSL_TLSv1_1 = 16;

	/**
	 * @see SWOOLE_SSL_TLSv1_2
	 */
	case SWOOLE_SSL_TLSv1_2 = 32;

	/**
	 * @see SWOOLE_SSL_TLSv1_3
	 */
	case SWOOLE_SSL_TLSv1_3 = 64;

	/**
	 * @see SWOOLE_SSL_SSLv2
	 */
	case SWOOLE_SSL_SSLv2 = 2;

	/**
	 * @see SWOOLE_SSL_SSLv3
	 */
	case SWOOLE_SSL_SSLv3 = 4;
}