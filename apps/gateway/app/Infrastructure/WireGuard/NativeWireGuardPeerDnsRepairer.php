<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NativeWireGuardPeerDnsRepairer implements WireGuardPeerDnsRepairer
{
    public function __construct(
        private VpnConfigurationRepository $configuration,
        private SshExecutor $ssh,
        private SshKeyProvider $sshKeys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function repair(Node $node): void
    {
        $vpn = $this->configuration->forPeer($node);
        $domains = $vpn->usesDefaultDnsResolver
            ? ['.']
            : array_values(array_unique(array_filter([$vpn->domain, $node->tld])));
        $dnsMode = $vpn->dnsThroughWireGuard ? 'wireguard' : 'underlay';
        $result = $this->ssh->execute(
            new SshConnection(
                host: (string) $node->wireguard_ip,
                user: $node->user,
                port: 22,
                identityFile: $this->sshKeys->privateKeyPath(),
                knownHostsFile: $this->knownHosts->path(),
            ),
            $this->command($dnsMode, $vpn->dnsServer, (string) $node->wireguard_public_key, $domains),
        );

        if ($result->succeeded()) {
            return;
        }

        [$step, $errorCode, $message] = match ($result->exitCode) {
            41 => [
                'wireguard-peer-lock',
                'vpn.peer_dns_busy',
                "Another WireGuard peer operation is active on node [{$node->name}].",
            ],
            42 => [
                'wireguard-peer-transaction',
                'vpn.peer_recovery_pending',
                "WireGuard peer recovery is still pending for node [{$node->name}].",
            ],
            43 => [
                'wireguard-peer-state',
                'vpn.peer_dns_state_unsupported',
                "The existing WireGuard DNS state is not repairable on node [{$node->name}].",
            ],
            44 => [
                'wireguard-peer-dns-candidate',
                'vpn.peer_dns_candidate_invalid',
                "Could not validate the repaired DNS configuration on node [{$node->name}].",
            ],
            45 => [
                'wireguard-peer-dns-apply',
                'vpn.peer_dns_apply_failed',
                "Could not apply repaired DNS configuration on node [{$node->name}].",
            ],
            46 => [
                'wireguard-peer-dns-recovery',
                'vpn.peer_dns_recovery_failed',
                "Could not restore the preceding DNS configuration on node [{$node->name}].",
            ],
            default => [
                'wireguard-peer-dns-repair',
                'vpn.peer_dns_repair_failed',
                "Could not repair DNS configuration on node [{$node->name}].",
            ],
        };

        throw new NodeProvisioningException(
            step: $step,
            errorCode: $errorCode,
            message: $message,
            result: $result,
        );
    }

    /** @param non-empty-list<string> $domains */
    private function command(string $dnsMode, string $dnsServer, string $publicKey, array $domains): RemoteCommand
    {
        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $dnsMode,
                $dnsServer,
                $publicKey,
                ...$domains,
            ],
            input: str_replace(
                '__TRANSACTION_OWNER__',
                'dns-repair-v1',
                <<<'BASH_WRAP'
                dns_mode=$1
                dns_server=$2
                expected_public_key=$3
                shift 3
                dns_domains=("$@")

                case "$dns_mode" in
                    wireguard|underlay) ;;
                    *) exit 43 ;;
                esac
                if [[ ! "$dns_server" =~ ^[A-Fa-f0-9:.]+$ ]] \
                    || [[ ! "$expected_public_key" =~ ^[A-Za-z0-9+/]{43}=$ ]] \
                    || [ "${#dns_domains[@]}" -lt 1 ] \
                    || [ "${#dns_domains[@]}" -gt 2 ]; then
                    exit 43
                fi
                for dns_domain in "${dns_domains[@]}"; do
                    if [[ ! "$dns_domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
                        exit 43
                    fi
                done
                if [[ " ${dns_domains[*]} " = *' . '* ]] && [ "${#dns_domains[@]}" -ne 1 ]; then
                    exit 43
                fi

                exec 9>/run/lock/orbit-wireguard-peer.lock
                if ! flock -w 30 9; then
                    exit 41
                fi

                live=/etc/wireguard/orbit.conf
                candidate=/etc/wireguard/orbit-candidate.conf
                backup=/etc/wireguard/.orbit.conf.rollback
                restore_candidate=/etc/wireguard/.orbit.conf.restore
                dns_state=/etc/wireguard/orbit.dns-link
                dns_state_candidate=/etc/wireguard/.orbit.dns-link.candidate
                dns_state_backup=/etc/wireguard/.orbit.dns-link.rollback
                dns_restore_candidate=/etc/wireguard/.orbit.dns-link.restore
                transaction=/etc/wireguard/.orbit.peer-transaction
                transaction_candidate=/etc/wireguard/.orbit.peer-transaction.candidate
                transaction_owner=__TRANSACTION_OWNER__

                read_dns_state() {
                    local path=$1
                    if [ ! -s "$path" ]; then
                        return 1
                    fi

                    mapfile -t parsed_dns_state < "$path"
                    parsed_dns_link=${parsed_dns_state[0]:-}
                    parsed_dns_server=${parsed_dns_state[1]:-}
                    parsed_dns_domains=("${parsed_dns_state[@]:2}")
                    if [[ ! "$parsed_dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]] \
                        || [[ ! "$parsed_dns_server" =~ ^[A-Fa-f0-9:.]+$ ]] \
                        || [ "${#parsed_dns_domains[@]}" -lt 1 ] \
                        || [ "${#parsed_dns_domains[@]}" -gt 2 ]; then
                        return 1
                    fi
                    for parsed_dns_domain in "${parsed_dns_domains[@]}"; do
                        if [[ ! "$parsed_dns_domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
                            return 1
                        fi
                    done
                }

                cleanup_transaction() {
                    rm -f -- "$candidate" "$dns_state_candidate" "$transaction_candidate" \
                        "$restore_candidate" "$dns_restore_candidate" || return 1
                    rm -f -- "$backup" "$dns_state_backup" || return 1
                    rm -f -- "$transaction" || return 1
                    if [ -e "$backup" ] || [ -L "$backup" ] \
                        || [ -e "$dns_state_backup" ] || [ -L "$dns_state_backup" ] \
                        || [ -e "$transaction" ] || [ -L "$transaction" ]; then
                        return 1
                    fi
                }

                recover_transaction() {
                    if [ ! -f "$backup" ] || [ -L "$backup" ] \
                        || { [ ! -f "$dns_state_backup" ] && [ ! -L "$dns_state_backup" ]; } \
                        || ! read_dns_state "$dns_state_backup"; then
                        return 1
                    fi

                    old_dns_link=$parsed_dns_link
                    old_dns_server=$parsed_dns_server
                    old_dns_domains=("${parsed_dns_domains[@]}")
                    current_dns_link=
                    if read_dns_state "$dns_state"; then
                        current_dns_link=$parsed_dns_link
                    fi

                    recovery_failed=0
                    if [[ "$current_dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]]; then
                        resolvectl dns "$current_dns_link" '' || recovery_failed=1
                        resolvectl domain "$current_dns_link" '' || recovery_failed=1
                    fi
                    rm -f -- "$restore_candidate" "$dns_restore_candidate" || recovery_failed=1
                    cp -a --no-dereference -- "$backup" "$restore_candidate" || recovery_failed=1
                    if [ -e "$restore_candidate" ] || [ -L "$restore_candidate" ]; then
                        mv -fT -- "$restore_candidate" "$live" || recovery_failed=1
                    fi
                    cp -a --no-dereference -- "$dns_state_backup" "$dns_restore_candidate" || recovery_failed=1
                    if [ -e "$dns_restore_candidate" ] || [ -L "$dns_restore_candidate" ]; then
                        mv -fT -- "$dns_restore_candidate" "$dns_state" || recovery_failed=1
                    fi
                    resolvectl dns "$old_dns_link" "$old_dns_server" || recovery_failed=1
                    old_resolvectl_domains=()
                    for old_dns_domain in "${old_dns_domains[@]}"; do
                        old_resolvectl_domains+=("~$old_dns_domain")
                    done
                    resolvectl domain "$old_dns_link" "${old_resolvectl_domains[@]}" || recovery_failed=1
                    if [ "$recovery_failed" -ne 0 ]; then
                        return 1
                    fi

                    cleanup_transaction
                }

                if [ -e "$transaction" ] || [ -L "$transaction" ]; then
                    if [ ! -f "$transaction" ] || [ -L "$transaction" ] \
                        || [ "$(head -n 1 "$transaction")" != "$transaction_owner" ]; then
                        exit 42
                    fi
                    if ! recover_transaction; then
                        exit 46
                    fi
                elif [ -e "$candidate" ] || [ -L "$candidate" ] \
                    || [ -e "$backup" ] || [ -L "$backup" ] \
                    || [ -e "$dns_state_candidate" ] || [ -L "$dns_state_candidate" ] \
                    || [ -e "$dns_state_backup" ] || [ -L "$dns_state_backup" ] \
                    || [ -e "$transaction_candidate" ] || [ -L "$transaction_candidate" ] \
                    || [ -e "$restore_candidate" ] || [ -L "$restore_candidate" ] \
                    || [ -e "$dns_restore_candidate" ] || [ -L "$dns_restore_candidate" ]; then
                    exit 42
                fi

                if [ ! -f "$live" ] || [ -L "$live" ] \
                    || { [ ! -f "$dns_state" ] && [ ! -L "$dns_state" ]; } \
                    || ! read_dns_state "$dns_state" \
                    || ! systemctl is-active --quiet wg-quick@orbit \
                    || ! active_public_key=$(wg show orbit public-key) \
                    || [ "$active_public_key" != "$expected_public_key" ]; then
                    exit 43
                fi
                old_dns_link=$parsed_dns_link
                old_dns_server=$parsed_dns_server
                old_dns_domains=("${parsed_dns_domains[@]}")

                if [ "$dns_mode" = wireguard ]; then
                    dns_link=orbit
                else
                    route=$(ip -o route get "$dns_server")
                    if [[ "$route" =~ [[:space:]]dev[[:space:]]([^[:space:]]+) ]]; then
                        dns_link=${BASH_REMATCH[1]}
                    else
                        exit 43
                    fi
                fi

                resolvectl_domains=()
                persistent_domains=()
                for dns_domain in "${dns_domains[@]}"; do
                    resolvectl_domains+=("~$dns_domain")
                    printf -v dns_domain_escaped '%q' "~$dns_domain"
                    persistent_domains+=("$dns_domain_escaped")
                done
                printf -v dns_server_escaped '%q' "$dns_server"
                dns_hooks=
                if [ "$dns_mode" = wireguard ]; then
                    dns_hooks="PostUp = resolvectl dns %i $dns_server_escaped; resolvectl domain %i ${persistent_domains[*]}"$'\n'
                fi

                hook_pattern="~^PostUp = resolvectl dns %i [^;\\r\\n]+; resolvectl domain %i [^\\r\\n]+\\r?\\n(?:^PreDown = (?:resolvectl revert %i|resolvectl dns %i ''; resolvectl domain %i '')\\r?\\n?)?~m"
                if ! php -r '
                    [$live, $candidate, $mode, $replacement, $pattern] = array_slice($argv, 1);
                    $configuration = file_get_contents($live);
                    if (!is_string($configuration)) { exit(1); }
                    $updated = preg_replace_callback($pattern, static fn (): string => $replacement, $configuration, -1, $count);
                    if (!is_string($updated) || $count > 1 || ($mode === "wireguard" && $count !== 1)) { exit(1); }
                    if (file_put_contents($candidate, $updated) === false) { exit(1); }
                ' -- "$live" "$candidate" "$dns_mode" "$dns_hooks" "$hook_pattern"; then
                    rm -f -- "$candidate" "$dns_state_candidate"
                    exit 44
                fi
                chown root:root "$candidate"
                chmod 0600 "$candidate"
                if ! wg-quick strip "$candidate" >/dev/null; then
                    rm -f -- "$candidate" "$dns_state_candidate"
                    exit 44
                fi
                if ! printf '%s\n' "$dns_link" "$dns_server" "${dns_domains[@]}" > "$dns_state_candidate"; then
                    rm -f -- "$candidate" "$dns_state_candidate"
                    exit 44
                fi
                chown root:root "$dns_state_candidate"
                chmod 0600 "$dns_state_candidate"

                if ! cp -a --no-dereference -- "$live" "$backup" \
                    || ! cp -a --no-dereference -- "$dns_state" "$dns_state_backup" \
                    || ! printf '%s\n' "$transaction_owner" > "$transaction_candidate" \
                    || ! chmod 0600 "$transaction_candidate" \
                    || ! mv -fT -- "$transaction_candidate" "$transaction"; then
                    rm -f -- "$candidate" "$dns_state_candidate" "$transaction_candidate" \
                        "$backup" "$dns_state_backup" "$transaction"
                    exit 44
                fi

                publication_failed=0
                mv -fT -- "$candidate" "$live" || publication_failed=1
                if [ "$publication_failed" -eq 0 ]; then
                    mv -fT -- "$dns_state_candidate" "$dns_state" || publication_failed=1
                fi
                if [ "$publication_failed" -ne 0 ]; then
                    if recover_transaction; then
                        exit 45
                    fi

                    exit 46
                fi

                apply_failed=0
                if [ "$old_dns_link" != "$dns_link" ]; then
                    resolvectl dns "$old_dns_link" '' || apply_failed=1
                    resolvectl domain "$old_dns_link" '' || apply_failed=1
                fi
                resolvectl dns "$dns_link" "$dns_server" || apply_failed=1
                resolvectl domain "$dns_link" "${resolvectl_domains[@]}" || apply_failed=1
                if ! active_public_key=$(wg show orbit public-key) \
                    || [ "$active_public_key" != "$expected_public_key" ]; then
                    apply_failed=1
                fi

                if [ "$apply_failed" -ne 0 ]; then
                    if recover_transaction; then
                        exit 45
                    fi

                    exit 46
                fi

                if ! cleanup_transaction; then
                    exit 46
                fi
                BASH_WRAP,
            ),
            maxOutputBytes: 4096,
        );
    }
}
