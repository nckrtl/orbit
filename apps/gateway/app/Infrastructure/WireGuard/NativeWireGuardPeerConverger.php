<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\GatewayPeerProjectionManager;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use Closure;

/** @mago-expect lint:cyclomatic-complexity The adapter keeps the fixed remote transaction and every bounded failure mapping together. */
final readonly class NativeWireGuardPeerConverger implements WireGuardPeerConverger, RecoverableWireGuardPeerConverger
{
    /** @var list<positive-int> */
    private const array PEER_INSTALL_RETRY_DELAYS = [1_000_000, 2_000_000];

    public function __construct(
        private VpnConfigurationRepository $configuration,
        private GatewayPeerProjectionManager $gatewayPeers,
        private SshExecutor $ssh,
        private ?Closure $sleep = null,
    ) {}

    public function converge(Node $node, SshConnection $connection): void
    {
        $publicKey = $this->preflightPeerKey($node, $connection, 'new');
        $this->publishPeer($node, $connection, $publicKey, 'finalize');
    }

    public function convergeRecoverably(Node $node, SshConnection $connection, Closure $completion): void
    {
        $publicKey = $this->preflightPeerKey($node, $connection, 'recoverable');

        try {
            $this->publishPeer($node, $connection, $publicKey, 'retain');
            $completion();
            $this->commitPeerTransaction($node, $connection, $publicKey);
        } catch (\Throwable $throwable) {
            $this->rollbackPeerTransaction($node, $connection);

            throw $throwable;
        }
    }

    private function preflightPeerKey(Node $node, SshConnection $connection, string $mode): string
    {
        $recoverable = $mode === 'recoverable';
        $keyResult = $this->ssh->execute(
            $connection,
            new RemoteCommand(
                arguments: ['sudo', 'bash', '-seu', '--', $mode],
                input: <<<'BASH'
                    mode=$1
                    exec 9>/run/lock/orbit-wireguard-peer.lock
                    flock -w 30 9
                    umask 077
                    if [ "$mode" = recoverable ]; then
                        if [ ! -s /etc/wireguard/orbit.key ]; then
                            exit 44
                        fi
                        wg pubkey < /etc/wireguard/orbit.key
                    else
                        install -d -m 0700 /etc/wireguard
                        if [ ! -s /etc/wireguard/orbit.key ]; then
                            wg genkey > /etc/wireguard/orbit.key
                        fi
                        wg pubkey < /etc/wireguard/orbit.key > /etc/wireguard/orbit.public
                        cat /etc/wireguard/orbit.public
                    fi
                    BASH,
            ),
        );
        $publicKey = trim($keyResult->stdout);

        if (! $keyResult->succeeded() || preg_match('/^[A-Za-z0-9+\/]{43}=$/', $publicKey) !== 1) {
            $errorCode = 'vpn.peer_key_failed';
            $message = $recoverable
                ? "Could not derive the existing WireGuard public key on node [{$node->name}]."
                : "Could not generate a WireGuard key on node [{$node->name}].";

            if ($recoverable && $keyResult->exitCode === 44) {
                $errorCode = 'vpn.peer_key_missing';
                $message = "Could not read the existing WireGuard key on node [{$node->name}].";
            }

            throw $this->failure(
                step: 'wireguard-peer-key',
                errorCode: $errorCode,
                message: $message,
                result: $keyResult,
            );
        }

        if ($recoverable && $node->wireguard_public_key !== $publicKey) {
            throw $this->failure(
                step: 'wireguard-peer-key',
                errorCode: 'vpn.peer_key_mismatch',
                message: "The existing WireGuard key does not match node [{$node->name}].",
                result: $keyResult,
            );
        }

        return $publicKey;
    }

    private function publishPeer(
        Node $node,
        SshConnection $connection,
        string $publicKey,
        string $transactionMode,
    ): void {
        $retainTransaction = $transactionMode === 'retain';
        $vpn = $this->configuration->forPeer($node);
        $node->update(['wireguard_public_key' => $publicKey]);
        $this->gatewayPeers->converge($node);
        $peerCommand = $this->peerCommand($vpn, $publicKey, $transactionMode);
        $peerResult = $this->ssh->execute($connection, $peerCommand);
        foreach (self::PEER_INSTALL_RETRY_DELAYS as $delay) {
            if (
                $peerResult->succeeded()
                || $peerResult->exitCode !== 255
                || ! str_starts_with($peerResult->stderr, 'ssh: connect to host ')
            ) {
                break;
            }

            ($this->sleep ?? usleep(...))($delay);
            $peerResult = $this->ssh->execute($connection, $peerCommand);
        }

        if (! $peerResult->succeeded()) {
            if ($retainTransaction && $peerResult->exitCode === 42) {
                throw $this->failure(
                    step: 'wireguard-peer-transaction',
                    errorCode: 'vpn.peer_recovery_pending',
                    message: "WireGuard peer recovery is still pending for node [{$node->name}].",
                    result: $peerResult,
                );
            }

            if ($peerResult->exitCode === 43) {
                throw $this->failure(
                    step: 'wireguard-peer-state',
                    errorCode: 'vpn.peer_state_unsupported',
                    message: "The existing WireGuard service state is not recoverable on node [{$node->name}].",
                    result: $peerResult,
                );
            }

            throw $this->failure(
                step: 'wireguard-peer-install',
                errorCode: 'vpn.peer_config_failed',
                message: "Could not configure WireGuard on node [{$node->name}].",
                result: $peerResult,
            );
        }
    }

    private function commitPeerTransaction(Node $node, SshConnection $connection, string $publicKey): void
    {
        $commitResult = $this->ssh->execute($connection, new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu', '--', $publicKey],
            input: <<<'BASH'
                active_public_key=$1
                exec 9>/run/lock/orbit-wireguard-peer.lock
                flock -w 30 9
                backup=/etc/wireguard/.orbit.conf.rollback
                dns_state_backup=/etc/wireguard/.orbit.dns-link.rollback
                transaction=/etc/wireguard/.orbit.peer-transaction
                candidate=/etc/wireguard/orbit-candidate.conf
                dns_state_candidate=/etc/wireguard/.orbit.dns-link.candidate
                transaction_candidate=/etc/wireguard/.orbit.peer-transaction.candidate
                restore_candidate=/etc/wireguard/.orbit.conf.restore
                dns_restore_candidate=/etc/wireguard/.orbit.dns-link.restore

                if [ ! -f "$transaction" ]; then
                    exit 0
                fi

                if ! current_public_key=$(wg show orbit public-key); then
                    exit 1
                fi

                if [ "$current_public_key" != "$active_public_key" ]; then
                    exit 1
                fi

                rm -f -- "$candidate" "$dns_state_candidate" "$transaction_candidate" \
                    "$restore_candidate" "$dns_restore_candidate" "$backup" "$dns_state_backup" "$transaction"
                if [ -e "$backup" ] || [ -L "$backup" ] \
                    || [ -e "$dns_state_backup" ] || [ -L "$dns_state_backup" ] \
                    || [ -e "$transaction" ] || [ -L "$transaction" ]; then
                    exit 1
                fi
                BASH,
        ));

        if (! $commitResult->succeeded()) {
            throw $this->failure(
                step: 'wireguard-peer-commit',
                errorCode: 'vpn.peer_commit_failed',
                message: "Could not finalize WireGuard recovery on node [{$node->name}].",
                result: $commitResult,
            );
        }
    }

    private function rollbackPeerTransaction(Node $node, SshConnection $connection): void
    {
        $rollbackResult = $this->ssh->execute($connection, new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu'],
            input: <<<'BASH'
                exec 9>/run/lock/orbit-wireguard-peer.lock
                flock -w 30 9
                live=/etc/wireguard/orbit.conf
                backup=/etc/wireguard/.orbit.conf.rollback
                restore_candidate=/etc/wireguard/.orbit.conf.restore
                dns_state=/etc/wireguard/orbit.dns-link
                dns_state_backup=/etc/wireguard/.orbit.dns-link.rollback
                dns_restore_candidate=/etc/wireguard/.orbit.dns-link.restore
                transaction=/etc/wireguard/.orbit.peer-transaction
                candidate=/etc/wireguard/orbit-candidate.conf
                dns_state_candidate=/etc/wireguard/.orbit.dns-link.candidate
                transaction_candidate=/etc/wireguard/.orbit.peer-transaction.candidate

                if [ ! -f "$transaction" ]; then
                    exit 0
                fi

                mapfile -t transaction_state < "$transaction"
                active_state=${transaction_state[0]:-}
                enabled_state=${transaction_state[1]:-}
                live_present=${transaction_state[2]:-}
                dns_state_present=${transaction_state[3]:-}

                case "$active_state" in
                    active|inactive) ;;
                    *) exit 1 ;;
                esac
                case "$enabled_state" in
                    enabled|enabled-runtime|disabled|masked|masked-runtime) ;;
                    *) exit 1 ;;
                esac
                case "$live_present:$dns_state_present" in
                    0:0|0:1|1:0|1:1) ;;
                    *) exit 1 ;;
                esac
                if [ "$live_present" = 1 ] && [ ! -f "$backup" ]; then
                    exit 1
                fi
                if [ "$live_present" = 0 ] && { [ -e "$backup" ] || [ -L "$backup" ]; }; then
                    exit 1
                fi
                if [ "$dns_state_present" = 1 ] && { [ ! -f "$dns_state_backup" ] && [ ! -L "$dns_state_backup" ]; }; then
                    exit 1
                fi
                if [ "$dns_state_present" = 0 ] && { [ -e "$dns_state_backup" ] || [ -L "$dns_state_backup" ]; }; then
                    exit 1
                fi

                old_dns_link=
                old_dns_server=
                old_dns_domain=
                if [ -s "$dns_state_backup" ]; then
                    mapfile -t old_dns_state < "$dns_state_backup"
                    old_dns_link=${old_dns_state[0]:-}
                    old_dns_server=${old_dns_state[1]:-}
                    old_dns_domain=${old_dns_state[2]:-}
                    if [[ ! "$old_dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]] \
                        || [[ ! "$old_dns_server" =~ ^[A-Fa-f0-9:.]+$ ]] \
                        || [[ ! "$old_dns_domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
                        exit 1
                    fi
                fi

                current_dns_link=
                if [ -s "$dns_state" ]; then
                    mapfile -t current_dns_state < "$dns_state"
                    current_dns_link=${current_dns_state[0]:-}
                fi

                if [[ "$current_dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]]; then
                    resolvectl revert "$current_dns_link"
                fi

                if [ -n "$old_dns_link" ]; then
                    resolvectl dns "$old_dns_link" "$old_dns_server"
                    resolvectl domain "$old_dns_link" "~$old_dns_domain"
                fi

                if [ "$active_state" = inactive ] && [ "$live_present" = 0 ]; then
                    systemctl stop wg-quick@orbit
                fi

                rm -f -- "$restore_candidate"
                if [ "$live_present" = 1 ]; then
                    cp -a --no-dereference -- "$backup" "$restore_candidate"
                    mv -fT -- "$restore_candidate" "$live"
                else
                    rm -f -- "$live"
                fi

                rm -f -- "$dns_restore_candidate"
                if [ "$dns_state_present" = 1 ]; then
                    cp -a --no-dereference -- "$dns_state_backup" "$dns_restore_candidate"
                    mv -fT -- "$dns_restore_candidate" "$dns_state"
                else
                    rm -f -- "$dns_state"
                fi

                systemctl unmask wg-quick@orbit
                systemctl unmask --runtime wg-quick@orbit
                systemctl disable wg-quick@orbit
                systemctl disable --runtime wg-quick@orbit
                case "$enabled_state" in
                    enabled) systemctl enable wg-quick@orbit ;;
                    enabled-runtime) systemctl enable --runtime wg-quick@orbit ;;
                esac

                if [ "$active_state" = active ]; then
                    systemctl restart wg-quick@orbit
                elif [ "$live_present" = 1 ]; then
                    systemctl stop wg-quick@orbit
                fi

                case "$enabled_state" in
                    masked) systemctl mask wg-quick@orbit ;;
                    masked-runtime) systemctl mask --runtime wg-quick@orbit ;;
                esac

                rm -f -- "$candidate" "$dns_state_candidate" "$transaction_candidate" \
                    "$restore_candidate" "$dns_restore_candidate" "$backup" "$dns_state_backup" "$transaction"
                if [ -e "$backup" ] || [ -L "$backup" ] \
                    || [ -e "$dns_state_backup" ] || [ -L "$dns_state_backup" ] \
                    || [ -e "$transaction" ] || [ -L "$transaction" ]; then
                    exit 1
                fi
                BASH,
        ));

        if (! $rollbackResult->succeeded()) {
            throw $this->failure(
                step: 'wireguard-rollback',
                errorCode: 'vpn.peer_rollback_failed',
                message: "Could not restore the remote WireGuard peer for node [{$node->name}].",
                result: $rollbackResult,
            );
        }
    }

    private function peerCommand(
        VpnConfiguration $vpn,
        string $peerPublicKey,
        string $transactionMode,
    ): RemoteCommand {
        $cleanup = $transactionMode === 'retain'
            ? 'trap - EXIT'
            : 'rm -f -- "$backup" "$dns_state_backup" "$transaction"'
            .PHP_EOL
            .'                if [ -e "$backup" ] || [ -L "$backup" ] || [ -e "$dns_state_backup" ] || [ -L "$dns_state_backup" ] || [ -e "$transaction" ] || [ -L "$transaction" ]; then'
            .PHP_EOL
            .'                    exit 1'
            .PHP_EOL
            .'                fi'
            .PHP_EOL
            .'                trap - EXIT';

        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $vpn->serverPublicKey,
                $peerPublicKey,
                $vpn->endpoint,
                $vpn->peerAddress,
                $vpn->subnet,
                $vpn->dnsServer,
                $vpn->domain,
                $vpn->dnsThroughWireGuard ? 'wireguard' : 'underlay',
                $transactionMode,
            ],
            input: str_replace(
                '__FINALIZE__',
                $cleanup,
                <<<'BASH_WRAP'
                    server_public_key=$1
                    peer_public_key=$2
                    endpoint=$3
                    address=$4
                    subnet=$5
                    dns_server=$6
                    domain=$7
                    dns_mode=$8
                    transaction_mode=$9
                    exec 9>/run/lock/orbit-wireguard-peer.lock
                    flock -w 30 9
                    private_key=$(cat /etc/wireguard/orbit.key)
                    candidate=/etc/wireguard/orbit-candidate.conf
                    live=/etc/wireguard/orbit.conf
                    backup=/etc/wireguard/.orbit.conf.rollback
                    dns_state=/etc/wireguard/orbit.dns-link
                    dns_state_candidate=/etc/wireguard/.orbit.dns-link.candidate
                    dns_state_backup=/etc/wireguard/.orbit.dns-link.rollback
                    transaction=/etc/wireguard/.orbit.peer-transaction
                    transaction_candidate=/etc/wireguard/.orbit.peer-transaction.candidate
                    restore_candidate=/etc/wireguard/.orbit.conf.restore
                    dns_restore_candidate=/etc/wireguard/.orbit.dns-link.restore
                    live_present=0
                    dns_state_present=0
                    published=0
                    transaction_owned=0
                    cleanup_finalize() {
                        rm -f -- "$candidate" "$dns_state_candidate" "$transaction_candidate" "$restore_candidate" "$dns_restore_candidate"
                        if [ "$transaction_owned" -eq 1 ] && [ "$published" -eq 0 ]; then
                            rm -f -- "$backup" "$dns_state_backup" "$transaction"
                        fi
                    }
                    trap cleanup_finalize EXIT

                    case "$dns_mode:$transaction_mode" in
                        wireguard:retain|wireguard:finalize|underlay:retain|underlay:finalize) ;;
                        *) exit 43 ;;
                    esac
                    if [ -e "$backup" ] || [ -L "$backup" ] \
                        || [ -e "$dns_state_backup" ] || [ -L "$dns_state_backup" ] \
                        || [ -e "$transaction" ] || [ -L "$transaction" ]; then
                        echo 'Existing peer recovery artifacts require manual recovery.' >&2
                        exit 42
                    fi
                    transaction_owned=1
                    if [ -L "$live" ] || { [ -e "$live" ] && [ ! -f "$live" ]; }; then
                        exit 43
                    fi
                    if [ -e "$dns_state" ] && [ ! -f "$dns_state" ] && [ ! -L "$dns_state" ]; then
                        exit 43
                    fi

                    active_state=$(systemctl is-active wg-quick@orbit 2>/dev/null || true)
                    enabled_state=$(systemctl is-enabled wg-quick@orbit 2>/dev/null || true)
                    case "$active_state" in
                        active|inactive) ;;
                        *) exit 43 ;;
                    esac
                    case "$enabled_state" in
                        enabled|enabled-runtime|disabled|masked|masked-runtime) ;;
                        *) exit 43 ;;
                    esac

                    if [ -f "$live" ]; then
                        live_present=1
                        cp -a --no-dereference -- "$live" "$backup"
                    fi
                    if [ -e "$dns_state" ] || [ -L "$dns_state" ]; then
                        dns_state_present=1
                        cp -a --no-dereference -- "$dns_state" "$dns_state_backup"
                    fi
                    printf '%s\n%s\n%s\n%s\n' \
                        "$active_state" "$enabled_state" "$live_present" "$dns_state_present" > "$transaction_candidate"
                    chmod 0600 "$transaction_candidate"
                    mv -fT -- "$transaction_candidate" "$transaction"
                    printf -v dns_server_escaped '%q' "$dns_server"
                    printf -v domain_escaped '%q' "~$domain"

                    dns_hooks=
                    if [ "$dns_mode" = wireguard ]; then
                        dns_hooks="PostUp = resolvectl dns %i $dns_server_escaped; resolvectl domain %i $domain_escaped"$'\n'"PreDown = resolvectl revert %i"
                    fi

                    old_dns_link=
                    old_dns_server=
                    old_dns_domain=
                    if [ -s "$dns_state" ]; then
                        mapfile -t old_dns_state < "$dns_state"
                        old_dns_link=${old_dns_state[0]:-}
                        old_dns_server=${old_dns_state[1]:-$dns_server}
                        old_dns_domain=${old_dns_state[2]:-$domain}
                        if [[ ! "$old_dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]] \
                            || [[ ! "$old_dns_server" =~ ^[A-Fa-f0-9:.]+$ ]] \
                            || [[ ! "$old_dns_domain" =~ ^[A-Za-z0-9.-]+$ ]]; then
                            old_dns_link=
                            old_dns_server=
                            old_dns_domain=
                        fi
                    fi

                    restore_dns_state() {
                        rm -f -- "$dns_restore_candidate" || return 1
                        if [ "$dns_state_present" -eq 1 ]; then
                            cp -a --no-dereference -- "$dns_state_backup" "$dns_restore_candidate" || return 1
                            mv -fT -- "$dns_restore_candidate" "$dns_state" || return 1
                        else
                            rm -f -- "$dns_state" || return 1
                        fi
                    }
                    restore_enabled_state() {
                        systemctl unmask wg-quick@orbit || return 1
                        systemctl unmask --runtime wg-quick@orbit || return 1
                        systemctl disable wg-quick@orbit || return 1
                        systemctl disable --runtime wg-quick@orbit || return 1
                        case "$enabled_state" in
                            enabled) systemctl enable wg-quick@orbit || return 1 ;;
                            enabled-runtime) systemctl enable --runtime wg-quick@orbit || return 1 ;;
                        esac
                    }

                    cat > "$candidate" <<EOF
                    [Interface]
                    PrivateKey = $private_key
                    Address = $address
                    $dns_hooks

                    [Peer]
                    PublicKey = $server_public_key
                    Endpoint = $endpoint
                    AllowedIPs = $subnet
                    PersistentKeepalive = 25
                    EOF

                    chown root:root "$candidate"
                    chmod 0600 "$candidate"
                    wg-quick strip "$candidate" >/dev/null
                    restore_previous() {
                        if [ "$active_state" = inactive ] && [ "$live_present" = 0 ]; then
                            systemctl stop wg-quick@orbit || return 1
                        fi
                        rm -f -- "$restore_candidate" || return 1
                        if [ "$live_present" -eq 1 ]; then
                            cp -a --no-dereference -- "$backup" "$restore_candidate" || return 1
                            mv -fT -- "$restore_candidate" "$live" || return 1
                        else
                            rm -f -- "$live" || return 1
                        fi
                        restore_dns_state || return 1
                        restore_enabled_state || return 1
                        if [ "$active_state" = active ]; then
                            systemctl restart wg-quick@orbit || return 1
                        elif [ "$live_present" = 1 ]; then
                            systemctl stop wg-quick@orbit || return 1
                        fi
                        case "$enabled_state" in
                            masked) systemctl mask wg-quick@orbit || return 1 ;;
                            masked-runtime) systemctl mask --runtime wg-quick@orbit || return 1 ;;
                        esac
                        rm -f -- "$backup" "$dns_state_backup" "$transaction" || return 1
                    }
                    dns_link=
                    restore_dns() {
                        if [[ "$dns_link" =~ ^[A-Za-z0-9_.:+-]+$ ]]; then
                            resolvectl revert "$dns_link" || return 1
                        fi
                        if [ -n "$old_dns_link" ]; then
                            resolvectl dns "$old_dns_link" "$old_dns_server" || return 1
                            resolvectl domain "$old_dns_link" "~$old_dns_domain" || return 1
                        fi
                    }
                    restore_after_failure() {
                        if [ "$transaction_mode" = retain ]; then
                            return 0
                        fi
                        restore_dns || return 1
                        restore_previous || return 1
                    }
                    if ! mv -f -- "$candidate" "$live"; then
                        exit 1
                    fi
                    published=1
                    case "$enabled_state" in
                        masked)
                            if ! systemctl unmask wg-quick@orbit; then
                                restore_after_failure || exit 1
                                exit 1
                            fi
                            ;;
                        masked-runtime)
                            if ! systemctl unmask --runtime wg-quick@orbit; then
                                restore_after_failure || exit 1
                                exit 1
                            fi
                            ;;
                    esac
                    if ! systemctl enable wg-quick@orbit; then
                        restore_after_failure || exit 1
                        exit 1
                    fi
                    if ! systemctl restart wg-quick@orbit; then
                        restore_after_failure || exit 1
                        exit 1
                    fi

                    if [ "$dns_mode" = underlay ]; then
                        route=$(ip -o route get "$dns_server")
                        if [[ "$route" =~ [[:space:]]dev[[:space:]]([^[:space:]]+) ]]; then
                            dns_link=${BASH_REMATCH[1]}
                        else
                            echo 'Could not resolve DNS interface.' >&2
                            restore_after_failure || exit 1
                            exit 1
                        fi
                        if ! resolvectl dns "$dns_link" "$dns_server"; then
                            restore_after_failure || exit 1
                            exit 1
                        fi
                        if ! resolvectl domain "$dns_link" "~$domain"; then
                            restore_after_failure || exit 1
                            exit 1
                        fi
                    else
                        dns_link=orbit
                    fi

                    if [ -n "$old_dns_link" ] && [ "$old_dns_link" != "$dns_link" ]; then
                        if ! resolvectl revert "$old_dns_link"; then
                            restore_after_failure || exit 1
                            exit 1
                        fi
                    fi
                    if ! printf '%s\n%s\n%s\n' "$dns_link" "$dns_server" "$domain" > "$dns_state_candidate"; then
                        restore_after_failure || exit 1
                        exit 1
                    fi
                    chmod 0600 "$dns_state_candidate"
                    if ! mv -f -- "$dns_state_candidate" "$dns_state"; then
                        restore_after_failure || exit 1
                        exit 1
                    fi
                    if ! active_public_key=$(wg show orbit public-key); then
                        restore_after_failure || exit 1
                        exit 1
                    fi
                    if [ "$active_public_key" != "$peer_public_key" ]; then
                        restore_after_failure || exit 1
                        exit 1
                    fi
                    __FINALIZE__
                    printf '%s\n' "$active_public_key"
                    BASH_WRAP,
            ),
        );
    }

    private function failure(
        string $step,
        string $errorCode,
        string $message,
        CommandResult $result,
    ): NodeProvisioningException {
        return new NodeProvisioningException(
            step: $step,
            errorCode: $errorCode,
            message: $message,
            result: $result,
        );
    }
}
