<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use Illuminate\Console\Command;

final class DeployDevelopmentDefaultsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:deploy-development-defaults';

    #[\Override]
    protected $description = 'Deploy changed default branches on every active app-dev default Instance.';

    public function handle(DeployDefaultInstanceAction $deploy): int
    {
        $failed = false;
        $instances = Instance::query()
            ->where('name', 'default')
            ->where('status', InstanceState::Active)
            ->where('source_layout', InstanceSourceLayout::Checkout->value)
            ->whereHas('node.roles', static fn ($query) => $query->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active))
            ->with(['project', 'node.roles'])
            ->orderBy('id')
            ->get();

        foreach ($instances as $instance) {
            if (! $instance->placedOnAppDev()) {
                continue;
            }
            $result = $deploy->execute($instance, onlyChanged: true, triggeredBy: 'schedule');
            $failure = $result?->failure;
            if ($failure !== null) {
                $failed = true;
                $this->error("Instance {$instance->id}: ".$failure->errorCode);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
