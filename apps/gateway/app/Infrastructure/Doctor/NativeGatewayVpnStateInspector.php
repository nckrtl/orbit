<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\GatewayVpnInspectionData;
use App\Domain\Doctor\GatewayVpnStateInspector;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevDnsConfigRenderer;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

final readonly class NativeGatewayVpnStateInspector implements GatewayVpnStateInspector
{
    private const string ACTIVE_SCRIPT = "if systemctl is-active --quiet wg-quick@orbit; then printf '1\\n'; else printf '0\\n'; fi";

    private const string COMPARE_SCRIPT = <<<'BASH'
        if sudo cmp -s -- "$1" -; then
            printf '1\n'
        else
            status=$?
            if [ "$status" -eq 1 ]; then
                printf '0\n'
            else
                exit "$status"
            fi
        fi
        BASH;

    /** @mago-expect lint:excessive-parameter-list Exact VPN inspection requires both renderers and the fixed SSH boundary. */
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private VpnConfigurationRepository $configuration,
        private WireGuardServerConfigRenderer $serverRenderer,
        private AppDevDnsConfigRenderer $dnsRenderer,
        private CommandDeadline $deadline,
    ) {}

    public function inspect(NodeRole $role): GatewayVpnInspectionData
    {
        try {
            $server = $this->server($role);
            $connection = $this->connection($server);
            $vpn = $this->configuration->forPeer($server);
            $serverConfig = $this->serverRenderer->render(
                $vpn,
                Node::query()->whereNotNull('wireguard_public_key')->get(),
            );
            $dnsConfig = $this->dnsRenderer->render();

            return new GatewayVpnInspectionData(
                $this->booleanResult($this->ssh->execute(
                    $connection,
                    new RemoteCommand(['bash', '-ceu', self::ACTIVE_SCRIPT]),
                )),
                $this->compare($connection, '/etc/wireguard/orbit.conf', $serverConfig),
                $this->compare($connection, '/etc/dnsmasq.d/orbit-records.conf', $dnsConfig),
            );
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }

    private function server(NodeRole $role): Node
    {
        $role->loadMissing('node');
        $node = $role->node;

        if ($role->role !== RoleName::Vpn || $role->node_id !== $node->id) {
            throw new DoctorInspectionException;
        }

        return $node;
    }

    private function connection(Node $node): SshConnection
    {
        $host = $node->wireguard_address;
        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }

        return new SshConnection(
            $host,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
            commandTimeout: $this->deadline->cap(30.0),
        );
    }

    private function compare(SshConnection $connection, string $path, string $expected): bool
    {
        return $this->booleanResult($this->ssh->execute(
            $connection,
            new RemoteCommand(
                ['bash', '-ceu', self::COMPARE_SCRIPT, '--', $path],
                protectedInput: ProtectedInput::fromString($expected),
            ),
        ));
    }

    private function booleanResult(CommandResult $result): bool
    {
        if (! $result->succeeded() || $result->truncated) {
            throw new DoctorInspectionException;
        }

        return match ($result->stdout) {
            "1\n" => true,
            "0\n" => false,
            default => throw new DoctorInspectionException,
        };
    }
}
