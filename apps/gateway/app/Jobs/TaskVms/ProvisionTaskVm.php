<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\TaskVms\TaskVmProvider;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmCloudInit;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Launches the VM with the cloud-init user-data, then queues its enrollment. A failed create is retried
 * once: a guest reboot can make a start fail once, while a too large VM fails every time.
 */
final class ProvisionTaskVm implements ShouldBeUnique, ShouldQueue
{
    use ProvisionsTaskVm;

    public int $tries = 2;

    public int $backoff = 30;

    public int $timeout = 300;

    public function handle(TaskVmProvider $provider, TaskVmCloudInit $cloudInit, SshKeyProvider $keys): void
    {
        $vm = $this->provisioning();
        // An enrolled VM is never created again: create() could start it in the middle of a guest reboot.
        if (! $vm instanceof TaskVm || $vm->node_id !== null) {
            return;
        }

        $provider->create($vm, $cloudInit->render($keys->publicKey()));
        EnrollTaskVm::dispatch($vm->id);
    }
}
