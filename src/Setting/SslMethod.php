<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Setting;

/**
 * This parameter has been removed in [v4.5.4](/version/bc?id=_454), please use `ssl_protocols` instead.
 *
 *  **Set the encryption method for OpenSSL tunnels.** 【Default value: `SWOOLE_SSLv23_METHOD`】, refer to [SSL
 *  encryption methods](/consts?id=ssl-encryption-methods) for supported types.
 *
 *  The algorithms used by `Server` and `Client` must be consistent, otherwise the `SSL/TLS` handshake will fail
 *  and the connection will be terminated.
 *
 *  ```
 *   $server->set(array(
 *       'ssl_method' => SWOOLE_SSLv3_CLIENT_METHOD,
 *   ));
 *  ```
 */
enum SslMethod: int
{
	/**
	 * @see SWOOLE_SSLv3_METHOD
	 */
	case SWOOLE_SSLv3_METHOD = 1;

	/**
	 * @see SWOOLE_SSLv3_SERVER_METHOD
	 */
	case SWOOLE_SSLv3_SERVER_METHOD = 2;

	/**
	 * @see SWOOLE_SSLv3_CLIENT_METHOD
	 */
	case SWOOLE_SSLv3_CLIENT_METHOD = 3;

	/**
	 * @see SWOOLE_SSLv23_METHOD
	 */
	case SWOOLE_SSLv23_METHOD = 0;

	/**
	 * @see SWOOLE_SSLv23_SERVER_METHOD
	 */
	case SWOOLE_SSLv23_SERVER_METHOD = 4;

	/**
	 * @see SWOOLE_SSLv23_CLIENT_METHOD
	 */
	case SWOOLE_SSLv23_CLIENT_METHOD = 5;

	/**
	 * @see SWOOLE_TLSv1_METHOD
	 */
	case SWOOLE_TLSv1_METHOD = 6;

	/**
	 * @see SWOOLE_TLSv1_SERVER_METHOD
	 */
	case SWOOLE_TLSv1_SERVER_METHOD = 7;

	/**
	 * @see SWOOLE_TLSv1_CLIENT_METHOD
	 */
	case SWOOLE_TLSv1_CLIENT_METHOD = 8;

	/**
	 * @see SWOOLE_TLSv1_1_METHOD
	 */
	case SWOOLE_TLSv1_1_METHOD = 9;

	/**
	 * @see SWOOLE_TLSv1_1_SERVER_METHOD
	 */
	case SWOOLE_TLSv1_1_SERVER_METHOD = 10;

	/**
	 * @see SWOOLE_TLSv1_1_CLIENT_METHOD
	 */
	case SWOOLE_TLSv1_1_CLIENT_METHOD = 11;

	/**
	 * @see SWOOLE_TLSv1_2_METHOD
	 */
	case SWOOLE_TLSv1_2_METHOD = 12;

	/**
	 * @see SWOOLE_TLSv1_2_SERVER_METHOD
	 */
	case SWOOLE_TLSv1_2_SERVER_METHOD = 13;

	/**
	 * @see SWOOLE_TLSv1_2_CLIENT_METHOD
	 */
	case SWOOLE_TLSv1_2_CLIENT_METHOD = 14;

	/**
	 * @see SWOOLE_DTLS_SERVER_METHOD
	 */
	case SWOOLE_DTLS_SERVER_METHOD = 16;

	/**
	 * @see SWOOLE_DTLS_CLIENT_METHOD
	 */
	case SWOOLE_DTLS_CLIENT_METHOD = 15;
}