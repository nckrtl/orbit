<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Models\Node;
use App\Models\TaskSandbox;
use Throwable;

/** A Node can host Pi threads when its managed `pi-server` Process is recorded as running. */
final readonly class PiNodeEligibility
{
    public function __construct(private SandboxFleetIdentity $identity) {}

    public function allows(Node $node): bool
    {
        if ($node->platform !== 'linux' || ! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return false;
        }

        if ($node->compute_sandbox_id !== null) {
            try {
                $sandbox = TaskSandbox::query()->find($node->compute_sandbox_id);
                if ($sandbox === null || $sandbox->pi_ready_at === null || $sandbox->pi_token === null
                    || $sandbox->model_key === null || $sandbox->model_key_registered_at === null || $sandbox->model_key_revoked_at !== null) {
                    return false;
                }
                $this->identity->assertReady($sandbox, $node);

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        return $node->processes()
            ->where('name', 'pi-server')
            ->where('runtime', ProcessRuntime::Systemd->value)
            ->where('status', LifecycleStatus::Active->value)
            ->where('desired_state', DesiredProcessState::Running->value)
            ->exists();
    }
}
