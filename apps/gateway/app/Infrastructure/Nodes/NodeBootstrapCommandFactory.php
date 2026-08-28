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

    public function make(Node $node, string $managedUser): RemoteCommand
    {
        return $this->command($managedUser);
    }

    public function makeWithPasswordlessSudo(Node $node, string $managedUser): RemoteCommand
    {
        return $this->command($managedUser, ['sudo', '-n', '--']);
    }

    /** @param list<string> $argumentPrefix */
    private function command(string $managedUser, array $argumentPrefix = []): RemoteCommand
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
                $managedUser,
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

                managed_user=$4
                orbit_key=$5
                shift
                shift
                shift
                shift
                shift

                export DEBIAN_FRONTEND=noninteractive
                apt-get update
                apt-get install --yes --no-install-recommends -- "$@"

                user_created=false
                if ! id -u -- "$managed_user" >/dev/null 2>&1; then
                    useradd --create-home --shell /bin/bash -- "$managed_user"
                    user_created=true
                fi

                managed_home=$(getent passwd -- "$managed_user" | cut -d: -f6)
                managed_group=$(id -gn -- "$managed_user")
                test -n "$managed_home"
                if [ -L "$managed_home" ] || [ ! -d "$managed_home" ]; then
                    printf '%s\n' "Managed home is not a real directory." >&2
                    exit 1
                fi
                if [ "$user_created" = true ]; then
                    install -d -m 0700 -o "$managed_user" -g "$managed_group" "$managed_home/.ssh" "$managed_home/.orbit"
                else
                    if [ -L "$managed_home/.ssh" ] || { [ -e "$managed_home/.ssh" ] && [ ! -d "$managed_home/.ssh" ]; }; then
                        printf '%s\n' "SSH directory is not a real directory." >&2
                        exit 1
                    fi
                    if [ -L "$managed_home/.orbit" ] || { [ -e "$managed_home/.orbit" ] && [ ! -d "$managed_home/.orbit" ]; }; then
                        printf '%s\n' "Orbit directory is not a real directory." >&2
                        exit 1
                    fi
                    if [ ! -d "$managed_home/.ssh" ]; then
                        install -d -m 0700 -o "$managed_user" -g "$managed_group" "$managed_home/.ssh"
                    fi
                    if [ ! -d "$managed_home/.orbit" ]; then
                        install -d -m 0700 -o "$managed_user" -g "$managed_group" "$managed_home/.orbit"
                    fi
                fi
                authorized_keys="$managed_home/.ssh/authorized_keys"
                if [ -L "$authorized_keys" ] || { [ -e "$authorized_keys" ] && [ ! -f "$authorized_keys" ]; }; then
                    printf '%s\n' "Authorized keys path is not a regular file." >&2
                    exit 1
                fi
                if [ ! -e "$authorized_keys" ]; then
                    install -m 0600 -o "$managed_user" -g "$managed_group" /dev/null "$authorized_keys"
                fi
                chown "$managed_user:$managed_group" -- "$authorized_keys"
                chmod 0600 -- "$authorized_keys"
                if ! grep -qxF "$orbit_key" "$managed_home/.ssh/authorized_keys"; then
                    printf '%s\n' "$orbit_key" >> "$managed_home/.ssh/authorized_keys"
                fi
                if ! sudo -n -u "$managed_user" -- sudo -n true >/dev/null 2>&1; then
                    sudoers=$(mktemp)
                    trap 'rm -f -- "$sudoers"' EXIT
                    printf '%s ALL=(ALL) NOPASSWD:ALL\n' "$managed_user" > "$sudoers"
                    chmod 0440 "$sudoers"
                    visudo -cf "$sudoers"
                    install -m 0440 -o root -g root "$sudoers" "/etc/sudoers.d/$managed_user"
                    trap - EXIT
                    rm -f -- "$sudoers"
                fi
                BASH,
        );
    }
}
