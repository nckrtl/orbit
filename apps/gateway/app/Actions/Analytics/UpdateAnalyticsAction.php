<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Nodes\AddNodeRoleAction;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeRole;

final readonly class UpdateAnalyticsAction
{
    public function __construct(
        private AnalyticsRoleSettingsRepository $settings,
        private AddNodeRoleAction $roles,
    ) {}

    /**
     * Pins another Plausible version and converges the role again, which replaces the `plausible`
     * Process. A failed convergence puts the earlier version back, so the next converge does not
     * retry a version that does not start.
     *
     * @return array{node_id: int, node_name: string, version: string, previous_version: string}
     */
    public function execute(string $version): array
    {
        $assignment = NodeRole::query()
            ->where('role', RoleName::Analytics->value)
            ->where('status', LifecycleStatus::Active->value)
            ->with('node')
            ->first();

        if (! $assignment instanceof NodeRole) {
            throw new ResourceOperationException(
                errorCode: 'analytics.role_missing',
                message: 'No Node has an active analytics role.',
                status: 409,
            );
        }

        $node = $assignment->node;
        $previous = $this->settings->version($node);

        if ($previous !== $version) {
            $this->settings->storeVersion($node, $version);

            try {
                $this->roles->execute($node, RoleName::Analytics, convergeExisting: true);
            } catch (\Throwable $exception) {
                $this->settings->storeVersion($node, $previous);

                throw $exception;
            }
        }

        return [
            'node_id' => $node->id,
            'node_name' => $node->name,
            'version' => $version,
            'previous_version' => $previous,
        ];
    }
}
