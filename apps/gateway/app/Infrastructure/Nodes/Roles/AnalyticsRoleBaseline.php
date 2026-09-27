<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Analytics\AnalyticsClickhouseConfigurationManager;
use App\Domain\Analytics\AnalyticsPublicationManager;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsSecretManager;
use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Analytics\PlausibleRuntimeLifecycle;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;

/**
 * Converges the analytics role: it proves the two storage Processes, applies Plausible's ClickHouse
 * configuration to the ClickHouse Process, runs Plausible as the node's `plausible` Process with the
 * URLs those Processes declare, and publishes `analytics.orbit`.
 */
final readonly class AnalyticsRoleBaseline implements RoleBaseline
{
    public function __construct(
        private AnalyticsRoleSettingsRepository $settings,
        private AnalyticsStorageProcessGuard $storage,
        private AnalyticsClickhouseConfigurationManager $clickhouse,
        private AnalyticsSecretManager $secrets,
        private PlausibleRuntimeLifecycle $runtime,
        private AnalyticsPublicationManager $publication,
        private NodeRoleFirewallManager $firewall,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $settings = $this->settings->find($node);

        if ($settings === null) {
            throw new ResourceOperationException(
                errorCode: 'analytics.settings_missing',
                message: "Node [{$node->name}] has no analytics storage Processes recorded.",
                status: 422,
            );
        }

        $processes = $this->storage->assert($settings->postgresProcessId, $settings->clickhouseProcessId);
        $this->clickhouse->converge($processes['clickhouse']);

        // Before Plausible starts: a storage Process on this Node is only reachable from the
        // `plausible` container once the role's own rules admit the Docker bridge.
        $this->firewall->converge($node, RoleName::Analytics, $node->user);

        $this->runtime->converge(
            $node,
            $this->settings->version($node),
            AnalyticsStorageConnection::from($processes['postgres'], $processes['clickhouse']),
            $this->secrets->secretKeyBase($node),
        );
        $this->publication->converge($node);
    }

    /** The two databases are never touched: the operator removes the storage Processes to remove the data. */
    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->publication->remove($node);
        $this->runtime->remove($node);
        // While the settings still name the storage Processes, so the local rules are known.
        $this->firewall->remove($node, RoleName::Analytics, $node->user);
        $this->secrets->purge($node);
        $this->settings->purge($node);
    }

    /** Removes only what lives on the Gateway: the DNS record, the Process record, and the settings. */
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->publication->removeUnreachable($node);
        $this->runtime->forget($node);
        $this->secrets->purge($node);
        $this->settings->purge($node);
    }
}
