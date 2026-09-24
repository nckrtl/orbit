<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\NodeCaddyListenerResolver;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Route;

final readonly class NativeProxyCliPublicationManager implements ProxyCliPublicationManager
{
    public function __construct(
        private GatewayCertificateIssuer $certificates,
        private ProxyCliCertificatePublisher $certificatePublisher,
        private ProxyCliCaddyPublisher $caddy,
        private ProxyCliCaddySiteRenderer $site,
        private PrivateDnsManager $dns,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ?ProxyCliRouteTakeover $takeover = null,
        private ?NodeCaddyListenerResolver $listeners = null,
    ) {}

    public function converge(Node $node, int $port = ProxyCliProcess::PORT, ?Route $takeover = null): void
    {
        $address = $this->address($node);
        $this->ensureCaddy($node, $address);
        $certificate = $this->certificates->issue(ProxyCliFootprint::Hostname, $address);
        $certificatePem = $this->read($certificate->certificatePath);
        $privateKeyPem = $this->read($certificate->privateKeyPath);
        $this->run(
            $node,
            $address,
            $this->certificatePublisher->command($certificatePem, $privateKeyPem),
            'proxycli.certificate_publication_failed',
            "proxycli certificate publication failed on node [{$node->name}].",
        );
        // Read inside the publication, so a takeover's withdrawn Route no longer counts.
        $publish = fn (?string $appDevFragment = null) => $this->run(
            $node,
            $address,
            $this->caddy->command(
                $this->site->render($port),
                (string) $port,
                ($this->listeners ?? app(NodeCaddyListenerResolver::class))->fragments($node),
                $appDevFragment,
            ),
            'proxycli.caddy_publication_failed',
            "proxycli Caddy publication failed on node [{$node->name}].",
        );

        if (! $takeover instanceof Route) {
            $publish();
            $this->dns->converge($node);

            return;
        }

        $routes = $this->takeover ?? app(ProxyCliRouteTakeover::class);
        $routes->publish($takeover, $node, $publish, $port);
        $this->dns->converge($node);
        $routes->remove($takeover);
    }

    public function remove(Node $node): void
    {
        $address = $this->address($node);
        $this->ssh->execute($this->connection($node, $address), $this->caddy->removeCommand());
        $this->ssh->execute($this->connection($node, $address), $this->certificatePublisher->removeCommand());
        $this->dns->converge();
    }

    private function ensureCaddy(Node $node, string $address): void
    {
        $this->run(
            $node,
            $address,
            new RemoteCommand(
                arguments: ['sudo', 'bash', '-seu', '--', ...CaddyPackageSourceProgram::arguments()],
                input: CaddyPackageSourceProgram::render(),
            ),
            'proxycli.caddy_publication_failed',
            "The Caddy package source failed on node [{$node->name}].",
        );
    }

    private function run(
        Node $node,
        string $address,
        RemoteCommand $command,
        string $errorCode,
        string $message,
    ): void {
        $result = $this->ssh->execute($this->connection($node, $address), $command);

        if (! $result->succeeded()) {
            throw new ResourceOperationException($errorCode, $message, 422);
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
            throw new ResourceOperationException(
                'proxycli.node_invalid',
                "Node [{$node->name}] has no valid WireGuard IPv4 address.",
                422,
            );
        }

        return $address;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new ResourceOperationException(
                'proxycli.certificate_read_failed',
                "Could not read the issued proxycli certificate at [{$path}].",
                422,
            );
        }

        return $contents;
    }
}
