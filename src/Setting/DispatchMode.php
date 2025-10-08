<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Setting;

/**
 * *Packet distribution strategy.** 【Default value: `2`】.
 *
 * *Tips**
 *
 *   **Recommendations**
 *
 *   Stateless servers can use `1` or `3`, synchronous blocking servers use `3`, and asynchronous non-blocking
 *   servers use `1` Stateful servers use `2`, `4`, `5`
 *
 *   **UDP Protocol**
 *
 *   For `dispatch_mode=2/4/5`, fixed allocation is employed, hashing client `IP` to different `Worker` processes
 *   For `dispatch_mode=1/3`, random allocation to different `Worker` processes
 *   `inet_addr_mod` function
 *
 *  ```
 *  function inet_addr_mod($ip, $worker_num) {
 *  $ip_parts = explode('.', $ip);
 *  if (count($ip_parts) != 4) {
 *  return false;
 *  }
 *  $ip_parts = array_reverse($ip_parts);
 *
 *  $ip_long = 0;
 *  foreach ($ip_parts as $part) {
 *  $ip_long <<= 8;
 *  $ip_long |= (int) $part;
 *  }
 *
 *  return $ip_long % $worker_num;
 *  }
 *  ```
 *
 *   **BASE Mode**
 *
 *   The `dispatch_mode` configuration is ineffective in [SWOOLE_BASE](/learn?id=swoole_base) mode because `BASE`
 *   does not involve task dispatching. Upon receiving data from a client, it immediately triggers the
 *   [onReceive](/server/events?id=onreceive) callback in the current thread/process without the need to dispatch to
 *   a `Worker` process.
 *
 *   **Note**
 *
 *  !> - When `dispatch_mode=1/3`, the `onConnect/onClose` events will be disabled at the underlying level, as these
 *  modes do not guarantee the order of `onConnect/onClose/onReceive`.
 *  - For non-request-response server programs, do not use modes `1` or `3`. For example, for an HTTP service
 *  (request-response), you can use `1` or `3`, but for TCP persistent connections, `1` or `3` should not be used.
 */
enum DispatchMode: int
{
	/**
	 * Each `Worker` process will be allocated sequentially for every received connection.
	 */
	case  ROUND_ROBIN = 1;

	/**
	 * Allocate `Worker` based on the connection's file descriptor. This ensures that data from the same connection
	 * will be processed by the same `Worker` only.
	 */
	case  FIXED = 2;

	/**
	 * The main process will choose delivery based on the workload status of the `Worker`, delivering only to idle
	 * `Workers`.
	 */
	case  PREEMPTIVE = 3;

	/**
	 * Allocate based on client `IP` using modulo `hash`, assigning to a specific `Worker` process.<br>This ensures
	 * that data from the same source IP connection will always be assigned to the same `Worker` process. Algorithm:
	 * `inet_addr_mod(ClientIP, worker_num)`.
	 */
	case  IP_HASH = 4;

	/**
	 * Requires binding a connection to a unique `uid` by calling [Server->bind()](/server/methods?id=bind) in the user
	 * code. Then, the underlying system allocates to different `Worker` processes based on the value of
	 * `UID`.<br>Algorithm: `UID % worker_num`. To use strings as `UID`, you can use `crc32(UID_STRING)`.
	 */
	case  UID_HASH = 5;

	/**
	 * Idle `Workers` will `accept` connections and handle new requests from the [Reactor](/learn?id=reactor-thread).
	 */
	case  STREAM = 7;
}