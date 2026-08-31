<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\WorkspaceInspectionData;
use App\Domain\Doctor\WorkspaceStateInspector;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/** @mago-expect lint:excessive-parameter-list Read-only workspace projection checks reuse the production renderers. */
final readonly class NativeWorkspaceStateInspector implements WorkspaceStateInspector
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ProcessRunner $processes,
        private AppDevCaddyConfigRenderer $caddy,
        private AppDevPhpFpmConfigRenderer $phpFpm,
        private CommandDeadline $deadline,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function inspect(Workspace $workspace): WorkspaceInspectionData
    {
        $workspace->loadMissing(['instance.node']);
        $instance = $workspace->instance;
        $site = new AppDevSite(
            nodeId: $instance->node_id,
            nodeAddress: $instance->node->wireguard_ip ?? '',
            scope: "workspace-{$workspace->id}",
            checkoutPath: $workspace->checkout_path,
            documentRoot: $instance->document_root,
            phpVersion: $workspace->php_version ?? $instance->php_version,
            hostname: $workspace->hostname,
        );
        /** @var Collection<int, AppDevSite> $sites */
        $sites = collect([$site]);

        try {
            $account = $this->accounts->resolve($instance->node);
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo',
                        'bash',
                        '-seu',
                        '--',
                        $instance->checkout_path,
                        $workspace->checkout_path,
                        $workspace->branch,
                        $instance->document_root,
                        base64_encode($this->caddy->render($sites)),
                        base64_encode($this->phpFpm->render($sites, $account)),
                        "/etc/php/{$site->phpVersion}/fpm/pool.d/orbit-scopes.conf",
                        $site->certificateDirectory(),
                        $account->home,
                    ],
                    input: $this->remoteScript(),
                ),
                step: 'doctor-workspace',
                errorCode: 'workspace.inspection_failed',
                commandTimeout: $this->deadline->cap(30.0),
            );
            $values = $this->parse($result, 7);
            $dns = $this->dnsMatches($workspace->hostname, $instance->node->wireguard_ip);
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        return new WorkspaceInspectionData(
            checkoutExists: $values[0],
            worktreeRegistered: $values[1],
            branchMatches: $values[2],
            documentRootExists: $values[3],
            caddyProjectionMatches: $values[4],
            phpFpmProjectionMatches: $values[5],
            certificateProjectionMatches: $values[6],
            dnsProjectionMatches: $dns,
        );
    }

    private function dnsMatches(string $hostname, ?string $address): bool
    {
        if (! is_string($address) || $address === '') {
            throw new DoctorInspectionException;
        }

        $result = $this->processes->run(new ProcessInvocation(
            arguments: ['bash', '-seu', '--', "host-record={$hostname},{$address}"],
            timeout: $this->deadline->cap(30.0),
            input: <<<'BASH'
                record=$1
                if grep -Fx -- "$record" /etc/dnsmasq.d/orbit-records.conf >/dev/null 2>&1; then
                    printf '1\n'
                else
                    printf '0\n'
                fi
                BASH,
        ));

        return $this->parse($result, 1)[0];
    }

    /** @return list<bool> */
    private function parse(CommandResult $result, int $count): array
    {
        $values = explode("\n", $result->stdout);
        $terminator = array_pop($values);
        if (
            ! $result->succeeded()
            || $result->truncated
            || $terminator !== ''
            || count($values) !== $count
            || array_diff($values, ['0', '1']) !== []
        ) {
            throw new DoctorInspectionException;
        }

        return array_map(static fn (string $value): bool => $value === '1', $values);
    }

    private function remoteScript(): string
    {
        return <<<'BASH'
            instance=$1
            checkout=$2
            branch=$3
            document_root=$4
            expected_caddy_base64=$5
            expected_php_fpm_base64=$6
            php_fpm_path=$7
            certificate_directory=$8
            managed_home=$9

            emit() {
                if "$@"; then printf '1\n'; else printf '0\n'; fi
            }

            safe_directory() {
                path=$1
                case "$path" in "$managed_home"|"$managed_home"/*) ;; *) return 1 ;; esac
                test -d "$path" && test ! -L "$path"
            }

            worktree_matches() {
                safe_directory "$instance" || return 1
                git -C "$instance" worktree list --porcelain 2>/dev/null | grep -Fx -- "worktree $checkout" >/dev/null
            }

            branch_matches() {
                safe_directory "$checkout" || return 1
                test "$(git -C "$checkout" symbolic-ref --quiet --short HEAD 2>/dev/null)" = "$branch"
            }

            document_root_matches() {
                safe_directory "$checkout" || return 1
                checkout_real=$(realpath -e "$checkout") || return 1
                path="$checkout/$document_root"
                test -d "$path" && test ! -L "$path" || return 1
                root_real=$(realpath -e "$path") || return 1
                case "$root_real" in "$checkout_real"|"$checkout_real"/*) return 0 ;; esac
                return 1
            }

            contains_exact_block() {
                path=$1
                encoded=$2
                test -f "$path" || return 1
                expected=$(printf '%s' "$encoded" | base64 --decode) || return 1
                actual=$(cat -- "$path") || return 1
                case "$actual" in *"$expected"*) return 0 ;; esac
                return 1
            }

            caddy_source=$(readlink -f /etc/caddy/Caddyfile 2>/dev/null || true)
            caddy_fragment="$(dirname "$caddy_source")/fragments/app-dev.caddy"
            emit safe_directory "$checkout"
            emit worktree_matches
            emit branch_matches
            emit document_root_matches
            emit contains_exact_block "$caddy_fragment" "$expected_caddy_base64"
            emit contains_exact_block "$php_fpm_path" "$expected_php_fpm_base64"
            if test -f "$certificate_directory/cert.pem" && test -f "$certificate_directory/key.pem"; then
                printf '1\n'
            else
                printf '0\n'
            fi
            BASH;
    }
}
