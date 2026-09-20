<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use SensitiveParameter;

/**
 * The Processes an assigned analytics role depends on: its own `plausible` Process, and the
 * PostgreSQL and ClickHouse Processes it was assigned with. An operator removes the role first.
 */
final readonly class AnalyticsProcessOwnership
{
    public function __construct(private AnalyticsRoleSettingsRepository $settings) {}

    public function assertRemovable(#[SensitiveParameter] Process $process): void
    {
        $assignment = NodeRole::query()->where('role', RoleName::Analytics->value)->with('node')->first();

        if (! $assignment instanceof NodeRole) {
            return;
        }

        $node = $assignment->node;
        $ownsPlausible = $process->owner_type === Node::class
            && $process->owner_id === $node->id
            && $process->name === PlausibleProcess::NAME;
        $settings = $this->settings->find($node);
        $isStorage = $settings instanceof AnalyticsRoleSettings
            && in_array($process->id, [$settings->postgresProcessId, $settings->clickhouseProcessId], true);

        if ($ownsPlausible || $isStorage) {
            throw new ResourceOperationException(
                errorCode: 'process.required_by_analytics',
                message: "Process [{$process->name}] belongs to the analytics role on Node [{$node->name}]. Remove that role first.",
                status: 409,
            );
        }
    }
}
