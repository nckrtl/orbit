<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Nodes\NodeCliInstaller;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The CLI install step of provisioning and role converge (ADR 0202). It runs on a Node of the rollout
 * set: a managed Linux Node with a role other than `gateway`. It is best effort: the CLI release may
 * not be published yet, and a failure only logs a warning, because the fleet rollout and its catch-up
 * install the CLI on every Node in the rollout set.
 */
final readonly class NodeCliConvergence
{
    public function __construct(
        private NodeCliInstaller $cli,
        private DesiredFleetState $desired,
        private FleetRolloutMembership $membership,
        private NodeCliState $state = new NodeCliState,
    ) {}

    public function converge(Node $node): ?NodeCliInstallation
    {
        if (! $this->eligible($node)) {
            return null;
        }

        try {
            $release = $this->desired->current()->cli;

            if (! $release->isAvailable()) {
                Log::info('The Orbit CLI was not installed: the desired CLI release is not published yet.', [
                    'node_id' => $node->id,
                    'reason' => $release->reason?->value,
                ]);

                return null;
            }

            $installation = $this->cli->ensure($node, $release);
            $this->state->clear($node);

            return $installation;
        } catch (Throwable $exception) {
            if ($exception instanceof ResourceOperationException && $exception->errorCode === 'cli.foreign_binary') {
                $this->state->markForeign($node);
            }

            Log::warning('The Orbit CLI install failed; the fleet catch-up installs it later.', [
                'node_id' => $node->id,
                'node_name' => $node->name,
                'error_code' => $exception instanceof ResourceOperationException ? $exception->errorCode : $exception::class,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /** A Node that is in the rollout set, or about to be: its first role may still be converging. */
    private function eligible(Node $node): bool
    {
        $reason = $this->membership->exclusion($node);

        if ($reason === null || $reason === 'foreign_cli') {
            return true;
        }

        return $reason === 'roleless'
            && $node->roles()->where('role', '!=', RoleName::Gateway->value)->where('status', '!=', LifecycleStatus::Removing->value)->exists();
    }
}
