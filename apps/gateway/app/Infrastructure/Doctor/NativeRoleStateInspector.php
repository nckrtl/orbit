<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\RoleInspectionData;
use App\Domain\Doctor\RoleStateInspector;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Firewall\UfwStatusParser;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

final readonly class NativeRoleStateInspector implements RoleStateInspector
{
    private const string PACKAGE_SCRIPT = <<<'BASH'
        present=1
        docker_ce_healthy=false
        if [ "$(dpkg-query -W -f='${Status}' docker-ce 2>/dev/null)" = 'install ok installed' ] \
            && [ "$(dpkg-query -W -f='${Status}' docker-ce-cli 2>/dev/null)" = 'install ok installed' ] \
            && [ "$(dpkg-query -W -f='${Status}' containerd.io 2>/dev/null)" = 'install ok installed' ] \
            && test -x /usr/bin/docker \
            && systemctl is-active --quiet docker; then
            docker_ce_healthy=true
        fi
        for package in "$@"; do
            if [ "$package" = docker.io ] && [ "$docker_ce_healthy" = true ]; then
                continue
            fi
            if ! dpkg-query -W -f='${db:Status-Abbrev}\n' -- "$package" 2>/dev/null | grep -qx 'ii '; then
                present=0
            fi
        done
        printf '%s\n' "$present"
        BASH;

    private const string SERVICE_SCRIPT = <<<'BASH'
        active=1
        for service in "$@"; do
            if ! systemctl is-active --quiet "$service"; then
                active=0
            fi
        done
        printf '%s\n' "$active"
        BASH;

    /** @mago-expect lint:excessive-parameter-list The inspector receives each read-only boundary and requirement owner. */
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private NodeBootstrapPackageCatalog $packages,
        private NodeRoleServiceCatalog $services,
        private NodeFirewallRuleCatalog $firewall,
        private CommandDeadline $deadline,
        private UfwStatusParser $firewallParser = new UfwStatusParser,
    ) {}

    public function inspect(NodeRole $role): RoleInspectionData
    {
        try {
            $role->loadMissing('node');
            $node = $role->node;
            $connection = $this->connection($node);
            $packages = $this->booleanResult($this->ssh->execute(
                $connection,
                new RemoteCommand(
                    ['bash', '-seu', '--', ...$this->packages->forRole($node, $role->role)],
                    self::PACKAGE_SCRIPT,
                ),
            ));
            $services = $this->booleanResult($this->ssh->execute(
                $connection,
                new RemoteCommand(
                    ['bash', '-seu', '--', ...$this->services->forRole($role->role)],
                    self::SERVICE_SCRIPT,
                ),
            ));
            $firewall = $this->ssh->execute(
                $connection,
                new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']),
            );

            return new RoleInspectionData(
                $packages,
                $services,
                $this->firewallMatches($firewall, $node, $role),
            );
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
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

    private function firewallMatches(CommandResult $result, Node $node, NodeRole $role): bool
    {
        if (! $result->succeeded() || $result->truncated) {
            throw new DoctorInspectionException;
        }
        if (preg_match('/\AStatus:\s+inactive\s*\z/i', $result->stdout) === 1) {
            return false;
        }
        if (preg_match('/\AStatus:\s+active\s*$/mi', $result->stdout) !== 1) {
            throw new DoctorInspectionException;
        }

        return array_all(
            $this->firewall->forRole($node, $role->role),
            fn (UfwManagedRule $rule): bool => (
                $this->firewallParser->ownership(
                    $result->stdout,
                    $rule->shape,
                ) === UfwRuleOwnership::Exact
            ),
        );
    }
}
