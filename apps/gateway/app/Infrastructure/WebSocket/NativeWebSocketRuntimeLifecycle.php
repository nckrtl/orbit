<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketRuntimeLifecycle;
use App\Infrastructure\Nodes\Roles\NodeRolePrerequisiteCommandFactory;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NativeWebSocketRuntimeLifecycle implements WebSocketRuntimeLifecycle
{
    public function __construct(
        private NodeRolePrerequisiteCommandFactory $commands,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ManagedUserAccountResolver $accounts,
        private WebSocketEnvironmentRenderer $environment = new WebSocketEnvironmentRenderer,
        private WebSocketServiceUnitRenderer $unit = new WebSocketServiceUnitRenderer,
        private string $repository = '',
        private string $ref = '',
        private string $installPath = '',
        private int $port = 0,
    ) {}

    public function converge(Node $node, NodeRole $assignment, WebSocketCredentials $credentials): void
    {
        $address = $this->address($node);
        $account = $this->accounts->resolve($node);

        $prerequisites = $this->ssh->execute(
            $this->connection($node, $address),
            $this->commands->make($node, RoleName::WebSocket, $account),
        );

        if (! $prerequisites->succeeded()) {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'websocket.prerequisite_failed',
                "WebSocket role prerequisites failed on node [{$node->name}].",
                $prerequisites,
            );
        }

        $port = $this->resolvedPort();
        $envContents = $this->environment->render($credentials, $port);
        $unitContents = $this->unit->render(
            $this->resolvedInstallPath(),
            $account->user,
            $account->group,
            $port,
        );

        $result = $this->ssh->execute(
            $this->connection($node, $address),
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    $account->user,
                    $account->group,
                    $this->resolvedInstallPath(),
                    $this->resolvedRepository(),
                    $this->resolvedRef(),
                ],
                protectedInput: ProtectedInput::fromString($this->script($envContents, $unitContents)),
            ),
        );

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'websocket-runtime',
                'node_role.convergence_failed',
                'websocket.runtime_convergence_failed',
                "WebSocket role convergence failed on node [{$node->name}].",
                $result,
            );
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $address = $this->address($node);
        $result = $this->ssh->execute(
            $this->connection($node, $address),
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    $this->resolvedInstallPath(),
                    $purgeData ? '1' : '0',
                ],
                input: <<<'BASH'
                    install_path=$1
                    purge_data=$2
                    service=orbit-websocket
                    if systemctl list-unit-files -- "$service.service" >/dev/null 2>&1; then
                        systemctl disable --now -- "$service" || true
                    fi
                    rm -f -- "/etc/systemd/system/$service.service"
                    systemctl daemon-reload || true
                    if [ "$purge_data" = 1 ]; then
                        rm -rf -- "$install_path"
                    fi
                    BASH,
            ),
        );

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'websocket-runtime-removal',
                'node_role.removal_failed',
                'websocket.runtime_removal_failed',
                "WebSocket role removal failed on node [{$node->name}].",
                $result,
            );
        }
    }

    public function health(Node $node): bool
    {
        $address = $this->address($node);
        $result = $this->ssh->execute(
            $this->connection($node, $address),
            new RemoteCommand(
                arguments: ['systemctl', 'is-active', '--quiet', 'orbit-websocket'],
            ),
        );

        return $result->succeeded();
    }

    private function script(string $envContents, string $unitContents): string
    {
        $envEncoded = base64_encode($envContents);
        $unitEncoded = base64_encode($unitContents);

        return <<<BASH
            managed_user=\$1
            managed_group=\$2
            install_path=\$3
            repository=\$4
            ref=\$5
            umask 0077
            install -d -m 0755 -- "\$(dirname "\$install_path")"
            if [ ! -d "\$install_path/.git" ]; then
                install -d -m 0755 -o "\$managed_user" -g "\$managed_group" -- "\$install_path"
                sudo -u "\$managed_user" -H git clone --quiet --no-checkout -- "\$repository" "\$install_path"
            fi
            sudo -u "\$managed_user" -H git -C "\$install_path" fetch --quiet origin "\$ref"
            sudo -u "\$managed_user" -H git -C "\$install_path" checkout --quiet --force FETCH_HEAD
            sudo -u "\$managed_user" -H git -C "\$install_path" reset --quiet --hard FETCH_HEAD
            sudo -u "\$managed_user" -H env COMPOSER_ALLOW_SUPERUSER=1 composer install \\
                --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir="\$install_path"
            env_candidate="\$install_path/.env.orbit-candidate"
            printf '%s' '{$envEncoded}' | base64 --decode > "\$env_candidate"
            chown "\$managed_user":"\$managed_group" "\$env_candidate"
            chmod 0600 "\$env_candidate"
            mv -fT -- "\$env_candidate" "\$install_path/.env"
            unit_candidate=/etc/systemd/system/.orbit-websocket.service.orbit-candidate
            verify_directory=\$(mktemp -d)
            trap 'rm -rf -- "\$verify_directory"; rm -f -- "\$unit_candidate"' EXIT
            printf '%s' '{$unitEncoded}' | base64 --decode > "\$verify_directory/orbit-websocket.service"
            systemd-analyze verify "\$verify_directory/orbit-websocket.service"
            install -o root -g root -m 0644 -- "\$verify_directory/orbit-websocket.service" "\$unit_candidate"
            mv -fT -- "\$unit_candidate" /etc/systemd/system/orbit-websocket.service
            systemctl daemon-reload
            systemctl enable --now orbit-websocket
            systemctl is-active --quiet orbit-websocket
            BASH;
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
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'websocket.wireguard_ip_missing',
                "Node [{$node->name}] has no WireGuard address.",
            );
        }

        return $node->wireguard_ip;
    }

    private function resolvedRepository(): string
    {
        return $this->repository !== '' ? $this->repository : (string) config('orbit.websocket.repository');
    }

    private function resolvedRef(): string
    {
        return $this->ref !== '' ? $this->ref : (string) config('orbit.websocket.ref');
    }

    private function resolvedInstallPath(): string
    {
        return $this->installPath !== '' ? $this->installPath : (string) config('orbit.websocket.install_path');
    }

    private function resolvedPort(): int
    {
        return $this->port > 0 ? $this->port : (int) config('orbit.websocket.port');
    }
}
