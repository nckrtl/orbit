<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliPublicationManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
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
        private NodeCaddyBuilds $builds,
        private PrivateDnsManager $dns,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ?ProxyCliRouteTakeover $takeover = null,
        private ?CaddySiteCertificates $siteCertificates = null,
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
        $this->certificateRecords()->record($node->id, CaddySiteCertificates::ProxyCli);

        if (! $takeover instanceof Route) {
            $this->build($node);
            $this->dns->converge($node);

            return;
        }

        $routes = $this->takeover ?? app(ProxyCliRouteTakeover::class);
        $routes->publish($takeover, $node, fn () => $this->build($node), $port);
        $this->dns->converge($node);
        $routes->remove($takeover);
    }

    public function remove(Node $node): void
    {
        $address = $this->address($node);
        // The extension is already disabled, so the build withdraws the collector site before its certificate.
        $this->build($node);
        $this->ssh->execute($this->connection($node, $address), $this->certificatePublisher->removeCommand());
        $this->certificateRecords()->forget($node->id, CaddySiteCertificates::ProxyCli);
        $this->dns->converge();
    }

    private function certificateRecords(): CaddySiteCertificates
    {
        return $this->siteCertificates ?? new CaddySiteCertificates;
    }

    /** The build renders the collector site from the stored extension state. */
    private function build(Node $node): void
    {
        try {
            $this->builds->build($node);
        } catch (NodeCaddyBuildException $exception) {
            throw new ResourceOperationException(
                'proxycli.caddy_publication_failed',
                $exception->getMessage(),
                422,
                $exception,
                $exception->details(),
            );
        }
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
