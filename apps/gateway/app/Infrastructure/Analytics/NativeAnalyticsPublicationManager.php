<?php

declare(strict_types=1);

namespace App\Infrastructure\Analytics;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\Analytics\AnalyticsPublicationManager;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NativeAnalyticsPublicationManager implements AnalyticsPublicationManager
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private AnalyticsCertificatePublisher $certificatePublisher,
        private NodeCaddyBuilds $builds,
        private PrivateDnsManager $dns,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function converge(Node $node): void
    {
        $address = $this->address($node);
        $this->awaitPlausible($node, $address);
        $this->ensureCaddy($node, $address);
        $certificate = $this->certificates->issue(AnalyticsHostname::Value, $address);
        $certificatePem = $this->read($certificate->certificatePath);
        $privateKeyPem = $this->read($certificate->privateKeyPath);

        $certificateResult = $this->ssh->execute(
            $this->connection($node, $address),
            $this->certificatePublisher->command($certificatePem, $privateKeyPem),
        );

        if (! $certificateResult->succeeded()) {
            throw new NodeRoleOperationException(
                'analytics-certificate',
                'node_role.convergence_failed',
                'analytics.certificate_publication_failed',
                "Analytics certificate publication failed on node [{$node->name}].",
                $certificateResult,
            );
        }

        $this->build($node);
        $this->dns->converge($node);
    }

    /**
     * The analytics role has no package step of its own, so the dashboard host is the point where
     * this Node needs a Caddy Orbit can render against. ADR 0100 owns the pinned source and floor.
     */
    private function ensureCaddy(Node $node, string $address): void
    {
        $result = $this->ssh->execute(
            $this->connection($node, $address),
            new RemoteCommand(
                arguments: ['sudo', 'bash', '-seu', '--', ...CaddyPackageSourceProgram::arguments()],
                input: CaddyPackageSourceProgram::render(),
            ),
        );

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'caddy-package-source',
                'node_role.convergence_failed',
                'analytics.caddy_publication_failed',
                "The Caddy package source failed on node [{$node->name}].",
                $result,
            );
        }
    }

    /**
     * A started container is not a working Plausible: it first creates and migrates its database,
     * and it restarts forever when it cannot reach its storage. The dashboard is published only
     * once Plausible answers its own health check.
     */
    private function awaitPlausible(Node $node, string $address): void
    {
        $result = $this->ssh->execute(
            $this->connection($node, $address),
            new RemoteCommand(
                ['bash', '-seu', '--', "http://{$address}:".PlausibleProcess::PORT.'/api/health'],
                <<<'BASH'
                    for attempt in $(seq 1 90); do
                        if curl --fail --silent --show-error --max-time 3 --output /dev/null "$1"; then
                            exit 0
                        fi
                        sleep 2
                    done
                    exit 1
                    BASH,
            ),
        );

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'analytics-health',
                'node_role.convergence_failed',
                'analytics.plausible_unhealthy',
                "Plausible did not become healthy on node [{$node->name}]. Read the logs of its plausible Process.",
                $result,
            );
        }
    }

    /** The role is already `removing`, so the build withdraws its site before the certificate goes. */
    public function remove(Node $node): void
    {
        $address = $this->address($node);

        $this->build($node);
        $this->ssh->execute($this->connection($node, $address), $this->certificatePublisher->removeCommand());
        $this->dns->converge();
    }

    public function removeUnreachable(Node $node): void
    {
        $this->dns->converge();
    }

    private function build(Node $node): void
    {
        try {
            $this->builds->build($node);
        } catch (NodeCaddyBuildException $exception) {
            throw new NodeRoleOperationException(
                'analytics-caddy',
                'node_role.convergence_failed',
                'analytics.caddy_publication_failed',
                $exception->getMessage(),
                $exception->result(),
                $exception,
            );
        }
    }

    private function connection(Node $node, string $address): SshConnection
    {
        return new SshConnection(
            $address,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
        );
    }

    private function address(Node $node): string
    {
        $address = $node->wireguard_ip;

        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new NodeRoleOperationException(
                'analytics-publication',
                'node_role.convergence_failed',
                'analytics.publication_address_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
            );
        }

        return $address;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new NodeRoleOperationException(
                'analytics-certificate',
                'node_role.convergence_failed',
                'analytics.certificate_read_failed',
                "Could not read the issued analytics certificate at [{$path}].",
            );
        }

        return $contents;
    }
}
