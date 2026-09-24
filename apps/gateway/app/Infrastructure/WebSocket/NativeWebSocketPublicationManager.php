<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\WebSocket\WebSocketHostname;
use App\Domain\WebSocket\WebSocketPublicationManager;
use App\Infrastructure\Caddy\Build\NodeCaddyListenerResolver;
use App\Infrastructure\Caddy\CaddyFragmentListeners;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NativeWebSocketPublicationManager implements WebSocketPublicationManager
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private WebSocketCertificatePublisher $certificatePublisher,
        private WebSocketCaddyPublisher $caddy,
        private WebSocketCaddySiteRenderer $site,
        private PrivateDnsManager $dns,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private int $port = 0,
        private ?NodeCaddyListenerResolver $listeners = null,
    ) {}

    public function converge(Node $node): void
    {
        $address = $this->address($node);
        $certificate = $this->certificates->issue(WebSocketHostname::Value, $address);
        $certificatePem = $this->read($certificate->certificatePath);
        $privateKeyPem = $this->read($certificate->privateKeyPath);

        $certificateResult = $this->ssh->execute(
            $this->connection($node, $address),
            $this->certificatePublisher->command($certificatePem, $privateKeyPem),
        );

        if (! $certificateResult->succeeded()) {
            throw new NodeRoleOperationException(
                'websocket-certificate',
                'node_role.convergence_failed',
                'websocket.certificate_publication_failed',
                "WebSocket certificate publication failed on node [{$node->name}].",
                $certificateResult,
            );
        }

        $configuration = $this->site->render($this->resolvedPort());
        $caddyResult = $this->ssh->execute(
            $this->connection($node, $address),
            $this->caddy->command($configuration, (string) $this->resolvedPort(), $this->listeners($node)),
        );

        if (! $caddyResult->succeeded()) {
            throw new NodeRoleOperationException(
                'websocket-caddy',
                'node_role.convergence_failed',
                'websocket.caddy_publication_failed',
                "WebSocket Caddy publication failed on node [{$node->name}].",
                $caddyResult,
            );
        }

        $this->dns->converge($node);
    }

    public function remove(Node $node): void
    {
        $address = $this->address($node);

        $this->ssh->execute($this->connection($node, $address), $this->caddy->removeCommand());
        $this->ssh->execute($this->connection($node, $address), $this->certificatePublisher->removeCommand());
        $this->dns->converge();
    }

    public function removeUnreachable(Node $node): void
    {
        $this->dns->converge();
    }

    private function listeners(Node $node): CaddyFragmentListeners
    {
        return ($this->listeners ?? app(NodeCaddyListenerResolver::class))->fragments($node);
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
                'websocket-publication',
                'node_role.convergence_failed',
                'websocket.publication_address_invalid',
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
                'websocket-certificate',
                'node_role.convergence_failed',
                'websocket.certificate_read_failed',
                "Could not read the issued websocket certificate at [{$path}].",
            );
        }

        return $contents;
    }

    private function resolvedPort(): int
    {
        return $this->port > 0 ? $this->port : (int) config('orbit.websocket.port');
    }
}
