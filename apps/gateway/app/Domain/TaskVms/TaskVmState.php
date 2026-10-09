<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/**
 * The lifecycle of one task VM row. Progress inside Provisioning is derived from facts
 * (the VM exists, `node_id` is set, the Node is active), never from marker timestamps.
 * Failed is terminal for the row; only destroying the VM moves it on.
 */
enum TaskVmState: string
{
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Destroying = 'destroying';
    case Destroyed = 'destroyed';
    case Failed = 'failed';
}
