<?php

declare(strict_types=1);

namespace App\Actions\Firewall;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;

final readonly class RemoveFirewallRuleAction
{
    public function __construct(
        private FirewallManager $firewall,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(FirewallRule $rule): FirewallBackendStatus
    {
        $rule->update([
            'status' => LifecycleStatus::Removing,
            'failed_step' => null,
            'error_code' => null,
        ]);

        try {
            $backendStatus = $this->firewall->remove($rule->loadMissing('node'));

            if ($backendStatus === FirewallBackendStatus::Inactive) {
                throw new FirewallOperationException(
                    step: 'status',
                    errorCode: 'firewall.backend_inactive',
                    message: "UFW is inactive on node [{$rule->node->name}].",
                    status: 503,
                );
            }
        } catch (FirewallOperationException $exception) {
            $rule->update([
                'status' => LifecycleStatus::Failed,
                'failed_step' => $exception->step,
                'error_code' => $exception->errorCode,
            ]);

            throw $exception;
        }

        $rule->delete();

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::FirewallDeleted,
            $rule->id,
            ['id' => $rule->id, 'name' => $rule->name],
        );

        return FirewallBackendStatus::Absent;
    }
}
