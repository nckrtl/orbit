<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NodeBootstrapCommandFactory
{
    /** @var non-empty-list<string> */
    private const array PACKAGES = [
        'ca-certificates',
        'curl',
        'gnupg',
        'openssh-client',
        'sudo',
        'ufw',
        'wireguard',
    ];

    public function __construct(
        private SshKeyProvider $keys,
    ) {}

    public function make(Node $node): RemoteCommand
    {
        return $this->command();
    }

    public function makeWithPasswordlessSudo(Node $node): RemoteCommand
    {
        return $this->command(['sudo', '-n', '--']);
    }

    /** @param list<string> $argumentPrefix */
    private function command(array $argumentPrefix = []): RemoteCommand
    {
        return new RemoteCommand(
            arguments: [
                ...$argumentPrefix,
                'bash',
                '-seu',
                '--',
                'ubuntu',
                implode(',', UbuntuRelease::supportedCodenames()),
                UbuntuRelease::requirementText(),
                $this->keys->publicKey(),
                ...self::PACKAGES,
            ],
            input: <<<'BASH'
                if [ ! -r /etc/os-release ]; then
                    printf '%s\n' "$3" >&2
                    exit 1
                fi

                os_id=''
                os_codename=''
                while IFS= read -r os_release_line || [ -n "$os_release_line" ]; do
                    case "$os_release_line" in
                        ''|'#'*) continue ;;
                        ID=*|VERSION_CODENAME=*)
                            os_release_key=${os_release_line%%=*}
                            os_release_value=${os_release_line#*=}
                            case "$os_release_value" in
                                \"*)
                                    [ "${os_release_value: -1}" = '"' ] || { printf '%s\n' "$3" >&2; exit 1; }
                                    os_release_value=${os_release_value:1:${#os_release_value}-2}
                                    ;;
                                \'*)
                                    [ "${os_release_value: -1}" = "'" ] || { printf '%s\n' "$3" >&2; exit 1; }
                                    os_release_value=${os_release_value:1:${#os_release_value}-2}
                                    ;;
                            esac
                            if [ -z "$os_release_value" ] || ! [[ "$os_release_value" =~ ^[A-Za-z0-9._-]+$ ]]; then
                                printf '%s\n' "$3" >&2
                                exit 1
                            fi
                            if [ "$os_release_key" = ID ]; then
                                [ -z "$os_id" ] || { printf '%s\n' "$3" >&2; exit 1; }
                                os_id=$os_release_value
                            else
                                [ -z "$os_codename" ] || { printf '%s\n' "$3" >&2; exit 1; }
                                os_codename=$os_release_value
                            fi
                            ;;
                        *)
                            continue
                            ;;
                    esac
                done < /etc/os-release

                selected_codename=''
                IFS=',' read -r -a allowed_codenames <<< "$2"
                for allowed_codename in "${allowed_codenames[@]}"; do
                    if [ "$os_id" = "$1" ] && [ "$os_codename" = "$allowed_codename" ]; then
                        selected_codename=$allowed_codename
                        break
                    fi
                done
                if [ -z "$selected_codename" ]; then
                    printf '%s\n' "$3" >&2
                    exit 1
                fi

                orbit_key=$4
                shift
                shift
                shift
                shift

                export DEBIAN_FRONTEND=noninteractive
                apt-get update
                apt-get install --yes --no-install-recommends -- "$@"

                if ! id -u orbit >/dev/null 2>&1; then
                    useradd --create-home --shell /bin/bash orbit
                fi

                test "$(getent passwd orbit | cut -d: -f6)" = /home/orbit
                install -d -m 0700 -o orbit -g orbit /home/orbit
                install -d -m 0700 -o orbit -g orbit /home/orbit/.ssh /home/orbit/.orbit
                touch /home/orbit/.ssh/authorized_keys
                if ! grep -qxF "$orbit_key" /home/orbit/.ssh/authorized_keys; then
                    printf '%s\n' "$orbit_key" >> /home/orbit/.ssh/authorized_keys
                fi
                chown orbit:orbit /home/orbit/.ssh/authorized_keys
                chmod 0600 /home/orbit/.ssh/authorized_keys

                sudoers=$(mktemp)
                printf 'orbit ALL=(ALL) NOPASSWD:ALL\n' > "$sudoers"
                chmod 0440 "$sudoers"
                visudo -cf "$sudoers"
                install -m 0440 -o root -g root "$sudoers" /etc/sudoers.d/orbit
                rm -f "$sudoers"
                BASH,
        );
    }
}
