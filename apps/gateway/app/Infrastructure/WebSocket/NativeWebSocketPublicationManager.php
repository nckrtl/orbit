<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\WebSocket\WebSocketHostname;
use App\Domain\WebSocket\WebSocketPublicationManager;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Closure;

final readonly class NativeWebSocketPublicationManager implements WebSocketPublicationManager
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private WebSocketCertificatePublisher $certificatePublisher,
        private NodeCaddyBuilds $builds,
        private PrivateDnsManager $dns,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ?CaddySiteCertificates $siteCertificates = null,
        private WebSocketDnsTarget $dnsTarget = new WebSocketDnsTarget,
    ) {}

    /** Publishes the certificate first, because validation loads every certificate the build names. */
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

        $this->certificateRecords()->record($node->id, CaddySiteCertificates::Websocket);

        $this->build($node, fn () => $this->builds->build($node));
        // Private DNS names this Node only once its build serves the site; during a move it keeps the old Node.
        $this->dnsTarget->markServing($node->id);
        $this->dns->converge($node);
    }

    /**
     * Refuses before the role changes anything when the Node lacks an address its Caddy sites would bind,
     * so a refused converge leaves a running Reverb alone.
     */
    public function checkListenAddresses(Node $node): void
    {
        $this->address($node);
        $this->build($node, fn () => $this->builds->checkListenAddresses($node));
    }

    /** The role is already `removing` or has moved, so the build withdraws the site before the certificate goes. */
    public function remove(Node $node): void
    {
        $address = $this->address($node);

        // Forgetting the certificate record withdraws the site in the build, also on the source of a move,
        // where the role row already names another Node. The build's Caddy reload closes the Node's Reverb
        // connections, and clients reconnect through private DNS that already names the new Node. The
        // serving mark stays until retire(), so a failed withdrawal keeps the Gateway on this Reverb too.
        $this->certificateRecords()->forget($node->id, CaddySiteCertificates::Websocket);
        $this->build($node, fn () => $this->builds->build($node));

        $this->ssh->execute($this->connection($node, $address), $this->certificatePublisher->removeCommand());
        $this->dns->converge();
    }

    public function retire(Node $node): void
    {
        $this->dnsTarget->forget($node->id);
    }

    /** The certificate may stay on the Node, so a later convergence publishes it again before its site renders. */
    public function removeUnreachable(Node $node): void
    {
        $this->dnsTarget->forget($node->id);
        $this->certificateRecords()->forget($node->id, CaddySiteCertificates::Websocket);
        $this->dns->converge();
    }

    private function certificateRecords(): CaddySiteCertificates
    {
        return $this->siteCertificates ?? new CaddySiteCertificates;
    }

    /** @param Closure(): mixed $operation */
    private function build(Node $node, Closure $operation): void
    {
        try {
            $operation();
        } catch (NodeCaddyBuildException $exception) {
            throw new NodeRoleOperationException(
                'websocket-caddy',
                'node_role.convergence_failed',
                'websocket.caddy_publication_failed',
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
}
