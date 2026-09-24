<?php

declare(strict_types=1);

namespace App\Actions\ProxyCli;

use App\Data\ProxyCli\EnableProxyCliData;
use App\Data\ProxyCli\ProxyCliStatusData;
use App\Domain\ProxyCli\ProxyCliHostname;
use App\Domain\ProxyCli\ProxyCliHostnameRoute;
use App\Domain\ProxyCli\ProxyCliPlacement;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Domain\ProxyCli\ProxyCliSnapshotStore;
use App\Domain\ProxyCli\ProxyCliState;
use App\Models\DatabaseConnection;
use SensitiveParameter;

final readonly class EnableProxyCliAction
{
    public function __construct(
        private ProxyCliPlacement $placement,
        private ProxyCliState $state,
        private ProxyCliRuntimeLifecycle $runtime,
        private ProxyCliPublicationManager $publication,
        private ProxyCliSnapshotStore $snapshots,
        private ProxyCliHostnameRoute $hostnameRoute = new ProxyCliHostnameRoute,
    ) {}

    public function execute(#[SensitiveParameter] EnableProxyCliData $data): ProxyCliStatusData
    {
        $node = $this->placement->node($data->nodeId);
        $connection = $this->placement->connection($data->cacheConnection);
        $takeover = $this->hostnameRoute->takeoverCandidate($node);
        $readToken = $this->state->readToken() ?? bin2hex(random_bytes(24));
        $controlToken = $this->state->controlToken() ?? bin2hex(random_bytes(24));
        $this->state->enable(
            $node->id,
            $data->cacheConnection,
            rtrim($data->cliproxyUrl, '/'),
            $data->cliproxyManagementKey,
            $readToken,
            $controlToken,
        );
        // Publish first: a takeover moves the name onto the collector site, which waits for the collector while
        // the runtime converge restarts it. The Route's site would fail those requests instead.
        $this->publication->converge($node, takeover: $takeover);
        $this->runtime->converge($node, [
            'PROXYCLI_CLIPROXY_URL' => rtrim($data->cliproxyUrl, '/'),
            'PROXYCLI_MANAGEMENT_KEY' => $data->cliproxyManagementKey,
            'PROXYCLI_READ_TOKEN' => $readToken,
            'PROXYCLI_CONTROL_TOKEN' => $controlToken,
            ...$this->cacheEnvironment($connection),
            'PROXYCLI_PORT' => (string) ProxyCliProcess::PORT,
        ]);

        return new ProxyCliStatusData(
            true,
            $node->id,
            $data->cacheConnection,
            $this->snapshots->snapshot()?->collectedAt,
            ProxyCliHostname::Value,
        );
    }

    /**
     * @return array<string, string>
     */
    private function cacheEnvironment(DatabaseConnection $connection): array
    {
        return [
            'PROXYCLI_CACHE_HOST' => is_string($connection->host) && $connection->host !== ''
                ? $connection->host
                : '127.0.0.1',
            'PROXYCLI_CACHE_PORT' => (string) ($connection->port ?? 6379),
            'PROXYCLI_CACHE_USERNAME' => $connection->username ?? '',
            'PROXYCLI_CACHE_PASSWORD' => $connection->password ?? '',
        ];
    }
}
