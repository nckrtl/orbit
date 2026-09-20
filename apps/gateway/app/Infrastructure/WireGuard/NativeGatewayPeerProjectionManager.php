<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use SensitiveParameter;

final readonly class NativeGatewayPeerProjectionManager implements GatewayPeerProjectionManager
{
    private const string GENERATED_CONFIG_PATH = '/generated/wireguard/orbit.conf';

    private const string CANDIDATE_CONFIG_PATH = '/etc/wireguard/orbit-candidate.conf';

    private const string LIVE_CONFIG_PATH = '/etc/wireguard/orbit.conf';

    private const string BACKUP_CONFIG_PATH = '/etc/wireguard/.orbit.conf.rollback';

    private const string LOCK_PATH = '/locks/wireguard-server.lock';

    public function __construct(
        private VpnConfigurationRepository $configuration,
        private WireGuardServerConfigRenderer $serverRenderer,
        private ProtectedFileWriter $files,
        private ProcessRunner $processes,
        private string $orbitHome,
        private ?SshExecutor $ssh = null,
        private ?SshKeyProvider $keys = null,
        private ?KnownHostsStore $knownHosts = null,
    ) {}

    public function converge(Node $node): void
    {
        $this->project($node, null);
    }

    public function remove(Node $node): void
    {
        $this->project($node, $node->id);
    }

    public function restore(Node $node): void
    {
        $this->project($node, null);
    }

    private function project(Node $context, ?int $excludedNodeId): void
    {
        $this->withLock(function () use ($context, $excludedNodeId): void {
            $vpn = $this->configuration->forPeer($context);
            $nodes = Node::query()->whereNotNull('wireguard_public_key');

            if ($excludedNodeId !== null) {
                $nodes->whereKeyNot($excludedNodeId);
            }

            $serverConfig = $this->serverRenderer->render($vpn, $nodes->get());
            $generatedPath = $this->path(self::GENERATED_CONFIG_PATH);
            $this->files->put($generatedPath, $serverConfig);
            $this->publish($vpn->server, $generatedPath);
        });
    }

    private function publish(Node $hub, string $generatedPath): void
    {
        $remote = $this->remoteHubOwner($hub);

        if ($remote instanceof Node) {
            $this->installRemote($remote, $generatedPath);

            return;
        }

        $this->install($generatedPath);
        $this->activate();
    }

    private function remoteHubOwner(Node $hub): ?Node
    {
        $gateway = $this->activeRoleHolder(RoleName::Gateway);

        if (! $gateway instanceof Node || $hub->is($gateway)) {
            return null;
        }

        return $hub;
    }

    private function activeRoleHolder(RoleName $role): ?Node
    {
        return Node::query()
            ->where('status', LifecycleStatus::Active)
            ->whereNotNull('wireguard_ip')
            ->whereHas(
                'roles',
                static fn ($query) => $query
                    ->where('role', $role)
                    ->where('status', LifecycleStatus::Active),
            )
            ->first();
    }

    private function withLock(callable $operation): void
    {
        $lockPath = $this->path(self::LOCK_PATH);
        $directory = dirname($lockPath);

        if (
            ! is_dir($directory)
            && ! mkdir(directory: $directory, permissions: 0o700, recursive: true)
            && ! is_dir($directory)
        ) {
            throw $this->failure('wireguard-server-lock', 'vpn.server_lock_failed');
        }

        chmod(filename: $directory, permissions: 0o700);
        $lock = fopen(filename: $lockPath, mode: 'c+');

        if ($lock === false) {
            throw $this->failure('wireguard-server-lock', 'vpn.server_lock_failed');
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw $this->failure('wireguard-server-lock', 'vpn.server_lock_failed');
            }

            $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function install(string $generatedPath): void
    {
        $backupCreated = false;

        try {
            $this->run(
                'wireguard-server-install',
                'vpn.server_config_install_failed',
                [
                    'sudo',
                    'install',
                    '-D',
                    '-o',
                    'root',
                    '-g',
                    'root',
                    '-m',
                    '0600',
                    '--',
                    $generatedPath,
                    self::CANDIDATE_CONFIG_PATH,
                ],
            );
            $this->run(
                'wireguard-server-validate',
                'vpn.server_config_invalid',
                ['sudo', 'wg-quick', 'strip', self::CANDIDATE_CONFIG_PATH],
            );
            $this->backup();
            $backupCreated = true;
            $this->run(
                'wireguard-server-install',
                'vpn.server_config_install_failed',
                ['sudo', 'mv', '-f', '--', self::CANDIDATE_CONFIG_PATH, self::LIVE_CONFIG_PATH],
            );
        } catch (NodeProvisioningException $exception) {
            $this->cleanup(
                $backupCreated
                    ? [self::CANDIDATE_CONFIG_PATH, self::BACKUP_CONFIG_PATH]
                    : [self::CANDIDATE_CONFIG_PATH],
            );

            throw $exception;
        }
    }

    private function backup(): void
    {
        $this->run(
            step: 'wireguard-server-install',
            errorCode: 'vpn.server_config_install_failed',
            arguments: ['sudo', 'bash', '-seu'],
            input: <<<'BASH'
                live=/etc/wireguard/orbit.conf
                backup=/etc/wireguard/.orbit.conf.rollback
                rm -f -- "$backup"
                if [ -f "$live" ]; then
                    cp --preserve=mode,ownership -- "$live" "$backup"
                fi
                BASH,
        );
    }

    private function installRemote(Node $hub, string $generatedPath): void
    {
        if ($this->ssh === null || $this->keys === null || $this->knownHosts === null) {
            throw $this->failure(
                step: 'wireguard-server-install',
                errorCode: 'vpn.server_config_install_failed',
                message: "Could not project the WireGuard hub onto node [{$hub->name}] without SSH.",
            );
        }

        $address = $hub->wireguard_ip;
        if (! is_string($address) || $address === '' || $hub->user === '') {
            throw $this->failure(
                step: 'wireguard-server-install',
                errorCode: 'vpn.server_config_install_failed',
                message: "Could not project the WireGuard hub onto node [{$hub->name}].",
            );
        }

        $config = file_get_contents($generatedPath);
        if (! is_string($config) || $config === '') {
            throw $this->failure(
                step: 'wireguard-server-install',
                errorCode: 'vpn.server_config_install_failed',
                message: "Could not project the WireGuard hub onto node [{$hub->name}].",
            );
        }

        $result = $this->ssh->execute(
            new SshConnection(
                host: $address,
                user: $hub->user,
                port: 22,
                identityFile: $this->keys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
                commandTimeout: 60.0,
            ),
            new RemoteCommand(
                arguments: ['sudo', 'bash', '-seu'],
                protectedInput: ProtectedInput::fromString($this->remoteInstallScript($config)),
            ),
        );

        if (! $result->succeeded()) {
            throw $this->failure(
                step: 'wireguard-server-restart',
                errorCode: 'vpn.server_start_failed',
                result: $result,
                message: "Could not converge the WireGuard hub on node [{$hub->name}].",
            );
        }
    }

    private function remoteInstallScript(#[SensitiveParameter] string $config): string
    {
        return str_replace(
            ['__HUB_CONFIG__', '__ACTIVATION__'],
            [base64_encode($config), $this->activationScript()],
            <<<'BASH'
                exec 9>/run/lock/orbit-wireguard-server.lock
                flock -w 30 9
                candidate=/etc/wireguard/orbit-candidate.conf
                live=/etc/wireguard/orbit.conf
                backup=/etc/wireguard/.orbit.conf.rollback
                install -d -m 0700 /etc/wireguard
                printf '%s' '__HUB_CONFIG__' | base64 --decode > "$candidate"
                chown root:root "$candidate"
                chmod 0600 "$candidate"
                if ! wg-quick strip "$candidate" >/dev/null; then
                    rm -f -- "$candidate"
                    exit 1
                fi
                rm -f -- "$backup"
                if [ -f "$live" ]; then
                    cp --preserve=mode,ownership -- "$live" "$backup"
                fi
                if ! mv -f -- "$candidate" "$live"; then
                    rm -f -- "$candidate" "$backup"
                    exit 1
                fi
                __ACTIVATION__
                BASH,
        );
    }

    private function activate(): void
    {
        $this->run(
            step: 'wireguard-server-restart',
            errorCode: 'vpn.server_start_failed',
            arguments: ['sudo', 'bash', '-seu'],
            input: $this->activationScript(),
        );
    }

    private function activationScript(): string
    {
        return <<<'BASH'
                live=/etc/wireguard/orbit.conf
                backup=/etc/wireguard/.orbit.conf.rollback
                runtime_config=
                was_active=false
                was_enabled=false
                if systemctl is-active --quiet wg-quick@orbit; then
                    was_active=true
                fi
                if systemctl is-enabled --quiet wg-quick@orbit; then
                    was_enabled=true
                fi
                sync_live() {
                    [ -n "$runtime_config" ] || return 1
                    if ! wg-quick strip "$live" > "$runtime_config"; then
                        return 1
                    fi
                    wg syncconf orbit "$runtime_config"
                }
                restore_state() {
                    if [ "$was_enabled" = true ]; then
                        systemctl enable wg-quick@orbit || true
                    else
                        systemctl disable wg-quick@orbit || true
                    fi
                }
                restore_previous() {
                    if [ -f "$backup" ]; then
                        mv -fT -- "$backup" "$live"
                        if [ "$was_active" = true ]; then
                            if [ -n "$runtime_config" ]; then
                                sync_live || systemctl restart wg-quick@orbit || true
                            fi
                        else
                            systemctl stop wg-quick@orbit || true
                        fi
                    else
                        rm -f -- "$live"
                        systemctl stop wg-quick@orbit || true
                    fi
                    restore_state
                }
                if ! systemctl enable wg-quick@orbit; then
                    restore_previous
                    exit 1
                fi
                if ! runtime_config=$(mktemp /etc/wireguard/orbit-wireguard.XXXXXX); then
                    restore_previous
                    exit 1
                fi
                if ! chmod 0600 "$runtime_config"; then
                    rm -f -- "$runtime_config"
                    runtime_config=
                    restore_previous
                    exit 1
                fi
                trap 'rm -f -- "$runtime_config"' EXIT
                if [ "$was_active" = true ]; then
                    if ! sync_live; then
                        restore_previous
                        exit 1
                    fi
                else
                    if ! systemctl start wg-quick@orbit; then
                        restore_previous
                        exit 1
                    fi
                fi
                if ! systemctl is-active --quiet wg-quick@orbit; then
                    restore_previous
                    exit 1
                fi
                if ! systemctl is-enabled --quiet wg-quick@orbit; then
                    restore_previous
                    exit 1
                fi
                rm -f -- "$backup"
                BASH;
    }

    /** @param non-empty-list<string> $arguments */
    private function run(string $step, string $errorCode, array $arguments, ?string $input = null): void
    {
        $result = $this->processes->run(new ProcessInvocation($arguments, input: $input));

        if (! $result->succeeded()) {
            throw $this->failure($step, $errorCode, $result);
        }
    }

    /** @param non-empty-list<string> $paths */
    private function cleanup(array $paths): void
    {
        $this->processes->run(new ProcessInvocation(['sudo', 'rm', '-f', '--', ...$paths]));
    }

    private function failure(
        string $step,
        string $errorCode,
        ?CommandResult $result = null,
        ?string $message = null,
    ): NodeProvisioningException {
        return new NodeProvisioningException(
            step: $step,
            errorCode: $errorCode,
            message: $message ?? 'Could not converge the gateway WireGuard service.',
            result: $result,
        );
    }

    private function path(string $suffix): string
    {
        return rtrim(string: $this->orbitHome, characters: '/').$suffix;
    }
}
