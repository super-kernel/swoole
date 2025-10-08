<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Setting;

/**
 * *Set the method of communication between the [Task worker](/learn?id=taskworker-process) and the `Worker`
 * processes.**【Default value: `1`】
 *
 * Please read about [IPC communication under Swoole](/learn?id=what-is-ipc) first.
 *
 * *Tips**
 *
 *   **Mode `1`**
 *   When using mode `1`, it supports targeted delivery. You can use `dst_worker_id` in the
 *   [task](/server/methods?id=task) and [taskwait](/server/methods?id=taskwait) methods to specify the target `Task
 *   worker`. When `dst_worker_id` is set to `-1`, the underlying system will determine the status of each [Task
 *   worker] and deliver tasks to the currently idle worker.
 *
 *   **Modes `2`, `3`**
 *   Message queue mode uses memory queues provided by the operating system to store data. If the
 *   `message_queue_key` is not specified, a private queue will be used, which will be deleted after the `Server`
 *   program terminates. If a message queue `Key` is specified, the data in the message queue will not be deleted
 *   after the `Server` program terminates, allowing processes to retrieve the data even after a restart. You can
 *   manually delete message queue data using `ipcrm -q` with the message queue `ID`. The difference between `Mode
 *   2` and `Mode 3` is that `Mode 2` supports targeted delivery. `$serv->task($data, $task_worker_id)` can specify
 *   which [Task worker] to deliver to. `Mode 3` is a complete grab mode where the [Task workers] will grab tasks
 *   from the queue, making targeted delivery impossible. Even if `$task_worker_id` is specified, it will be ignored
 *   under `Mode 3`.
 *
 *   **Note**
 *
 *  - Mode 3 will affect the [sendMessage](/server/methods?id=sendmessage) method, causing messages sent by
 *  [sendMessage](/server/methods?id=sendmessage) to be randomly picked up by a specific [Task worker].
 *  - When using message queue communication, if the processing capability of the `Task worker` is lower than the
 *  delivery speed, it may cause the `Worker` processes to block.
 *  - After using message queue communication, task workers cannot support coroutines (enabling
 *  [task_enable_coroutine](/server/setting?id=task_enable_coroutine)).
 */
enum TaskIpcMode: int
{
	/**
	 * Use `Unix Socket` communication【Default mode】.
	 */
	case UNIX_SOCKET = 1;

	/**
	 * Use `sysvmsg` message queue communication.
	 */
	case SYSV_MSG = 2;

	/**
	 * Use `sysvmsg` message queue communication and set to grab mode.
	 */
	case SCRAMBLE = 3;
}