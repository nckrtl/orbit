<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NodeRoleOperatingSystemGuard
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function assert(Node $node, RoleName $role): void
    {
        $wireGuardAddress = $node->wireguard_ip;

        if (! is_string($wireGuardAddress) || $wireGuardAddress === '') {
            throw new NodeRoleOperationException(
                'operating-system',
                'node_role.convergence_failed',
                'node_role.wireguard_missing',
                "Node [{$node->name}] role [{$role->value}] requires a WireGuard address.",
            );
        }

        $requiredReleases = UbuntuRelease::forRole($role);
        $result = $this->ssh->execute(
            new SshConnection(
                $wireGuardAddress,
                $node->user,
                22,
                $this->keys->privateKeyPath(),
                $this->knownHosts->path(),
            ),
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $role->value,
                    (string) count($requiredReleases),
                    UbuntuRelease::unsupportedText(),
                    ...array_map(
                        static fn (UbuntuRelease $release): string => $release->value,
                        $requiredReleases,
                    ),
                ],
                input: <<<'BASH'
                    role=$1
                    shift
                    release_count=$1
                    shift
                    unsupported_text=$1
                    shift

                    fail_os() {
                        if [ "$#" -eq 2 ] && [ -n "$1" ] && [ -n "$2" ]; then
                            printf 'Node operating system [%s/%s] is not supported.\n' "$1" "$2" >&2
                        else
                            printf '%s\n' "$unsupported_text" >&2
                        fi
                        exit 1
                    }

                    if ! [ -r /etc/os-release ]; then
                        fail_os
                    fi

                    os_id=
                    os_codename=
                    found_id=false
                    found_codename=false
                    while IFS= read -r line || [ -n "$line" ]; do
                        case "$line" in
                    ID=*)
                        [ "$found_id" = false ] || fail_os
                        os_id=${line#ID=}
                        if [[ "$os_id" =~ ^\"[A-Za-z0-9._-]+\"$ ]]; then os_id=${os_id:1:${#os_id}-2};
                        elif [[ "$os_id" =~ ^\'[A-Za-z0-9._-]+\'$ ]]; then os_id=${os_id:1:${#os_id}-2};
                        elif [[ ! "$os_id" =~ ^[A-Za-z0-9._-]+$ ]]; then fail_os; fi
                        found_id=true
                                ;;
                            VERSION_CODENAME=*)
                                [ "$found_codename" = false ] || fail_os
                                os_codename=${line#VERSION_CODENAME=}
                        if [[ "$os_codename" =~ ^\"[A-Za-z0-9._-]+\"$ ]]; then os_codename=${os_codename:1:${#os_codename}-2};
                        elif [[ "$os_codename" =~ ^\'[A-Za-z0-9._-]+\'$ ]]; then os_codename=${os_codename:1:${#os_codename}-2};
                        elif [[ ! "$os_codename" =~ ^[A-Za-z0-9._-]+$ ]]; then fail_os; fi
                        found_codename=true
                                ;;
                        esac
                    done < /etc/os-release

                    if [ "$found_id" != true ] || [ "$found_codename" != true ]; then
                        fail_os
                    fi

                    supported_release=false
                    for _ in $(seq 1 "$release_count"); do
                        supported_codename=$1
                        shift
                        if [ "$os_codename" = "$supported_codename" ]; then
                            supported_release=true
                        fi
                    done

                    if [ "$os_id" != 'ubuntu' ] || [ "$supported_release" != 'true' ]; then
                        fail_os "$os_id" "$os_codename"
                    fi
                    BASH,
            ),
        );

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'operating-system',
                'node_role.convergence_failed',
                'node_role.operating_system_unsupported',
                "Node [{$node->name}] role [{$role->value}]. "
                    .UbuntuRelease::unsupportedTextFromOutput($result->stderr),
                result: $result,
            );
        }
    }
}
