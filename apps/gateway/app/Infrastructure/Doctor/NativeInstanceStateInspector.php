<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\AppProd\AppProdCaddyConfigRenderer;
use App\Infrastructure\AppProd\AppProdPhpFpmConfigRenderer;
use App\Infrastructure\AppProd\AppProdSite;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\NodeRole;
use Illuminate\Support\Collection;

/**
 * @mago-expect lint:cyclomatic-complexity The inspector validates both explicit runtime modes and their projections.
 * @mago-expect lint:excessive-parameter-list Read-only projection checks reuse all four production renderers.
 */
final readonly class NativeInstanceStateInspector implements InstanceStateInspector
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ProcessRunner $processes,
        private AppDevCaddyConfigRenderer $appDevCaddy,
        private AppDevPhpFpmConfigRenderer $appDevPhpFpm,
        private AppProdCaddyConfigRenderer $appProdCaddy,
        private AppProdPhpFpmConfigRenderer $appProdPhpFpm,
        private CommandDeadline $deadline,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function inspect(Instance $instance): InstanceInspectionData
    {
        $instance->loadMissing(['app', 'node']);
        $appDevelopment = $this->isAppDevelopment($instance);
        try {
            $account = $appDevelopment ? $this->accounts->resolve($instance->node) : null;
            [$mode, $caddy, $phpFpm, $caddyFragment, $phpFpmPath, $certificateDirectory, $expectedCheckout] =
                $appDevelopment
                    ? $this->appDevelopmentProjection($instance, $account)
                    : $this->appProductionProjection($instance);
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: [
                        'sudo',
                        'bash',
                        '-seu',
                        '--',
                        $mode,
                        $instance->checkout_path,
                        $instance->document_root,
                        $caddyFragment,
                        $phpFpmPath,
                        base64_encode($caddy),
                        base64_encode($phpFpm),
                        $certificateDirectory,
                        $expectedCheckout,
                        $account?->home ?? '',
                    ],
                    input: $this->remoteScript(),
                ),
                step: 'doctor-instance',
                errorCode: 'instance.inspection_failed',
                commandTimeout: $this->deadline->cap(30.0),
            );
            $values = $this->parse($result, 5);
            $dns = $appDevelopment ? $this->dnsMatches($instance->hostname, $instance->node->wireguard_address) : null;
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        return new InstanceInspectionData(
            checkoutExists: $values[0],
            documentRootExists: $values[1],
            caddyProjectionMatches: $values[2],
            phpFpmProjectionMatches: $values[3],
            certificateProjectionMatches: $appDevelopment ? $values[4] : null,
            dnsProjectionMatches: $dns,
        );
    }

    private function isAppDevelopment(Instance $instance): bool
    {
        $roles = $instance
            ->node
            ->roles()
            ->where('status', LifecycleStatus::Active->value)
            ->whereIn('role', [RoleName::AppDev->value, RoleName::AppProd->value])
            ->limit(2)
            ->get();
        if ($roles->count() !== 1) {
            throw new DoctorInspectionException;
        }

        $role = $roles->first();
        if (! $role instanceof NodeRole) {
            throw new DoctorInspectionException;
        }
        $appDevelopment = $role->role === RoleName::AppDev;

        $expectedMode = match ($role->role) {
            RoleName::AppDev => CertificateMode::OrbitCa,
            RoleName::AppProd => CertificateMode::Acme,
            default => throw new DoctorInspectionException,
        };

        if ($instance->certificate_mode !== $expectedMode) {
            throw new DoctorInspectionException;
        }

        return $appDevelopment;
    }

    /** @return array{string, string, string, string, string, string, string} */
    private function appDevelopmentProjection(Instance $instance, ?ManagedUserAccount $account): array
    {
        if ($account === null) {
            throw new DoctorInspectionException;
        }
        $site = new AppDevSite(
            nodeId: $instance->node_id,
            nodeAddress: $instance->node->wireguard_address ?? '',
            scope: "instance-{$instance->id}",
            checkoutPath: $instance->checkout_path,
            documentRoot: $instance->document_root,
            phpVersion: $instance->php_version,
            hostname: $instance->hostname,
        );
        /** @var Collection<int, AppDevSite> $sites */
        $sites = collect([$site]);

        return [
            'app-dev',
            $this->appDevCaddy->render($sites),
            $this->appDevPhpFpm->render($sites, $account),
            'app-dev.caddy',
            "/etc/php/{$instance->php_version}/fpm/pool.d/orbit-scopes.conf",
            $site->certificateDirectory(),
            $instance->checkout_path,
        ];
    }

    /** @return array{string, string, string, string, string, string, string} */
    private function appProductionProjection(Instance $instance): array
    {
        $site = new AppProdSite(
            nodeId: $instance->node_id,
            appSlug: $instance->app->slug,
            instanceName: $instance->name,
            checkoutPath: $instance->checkout_path,
            documentRoot: $instance->document_root,
            phpVersion: $instance->php_version,
            hostname: $instance->hostname,
            instanceId: $instance->id,
        );
        /** @var Collection<int, AppProdSite> $sites */
        $sites = collect([$site]);

        return [
            'app-prod',
            $this->appProdCaddy->render($sites),
            $this->appProdPhpFpm->render($sites),
            'app-prod.caddy',
            "/etc/php/{$instance->php_version}/fpm/pool.d/orbit-prod-scopes.conf",
            '',
            "/var/www/{$instance->app->slug}/{$instance->name}",
        ];
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
            mode=$1
            checkout=$2
            document_root=$3
            caddy_fragment_name=$4
            php_fpm_path=$5
            expected_caddy_base64=$6
            expected_php_fpm_base64=$7
            certificate_directory=$8
            expected_checkout=$9
            managed_home=${10}

            emit() {
                if "$@"; then printf '1\n'; else printf '0\n'; fi
            }

            checkout_matches() {
                test "$checkout" = "$expected_checkout" || return 1
                case "$mode:$checkout" in
                    app-dev:"$managed_home"|app-dev:"$managed_home"/*|app-prod:/var/www/*) ;;
                    *) return 1 ;;
                esac
                test -d "$checkout" && test ! -L "$checkout"
            }

            document_root_matches() {
                checkout_matches || return 1
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
            caddy_fragment="$(dirname "$caddy_source")/fragments/$caddy_fragment_name"
            emit checkout_matches
            emit document_root_matches
            emit contains_exact_block "$caddy_fragment" "$expected_caddy_base64"
            emit contains_exact_block "$php_fpm_path" "$expected_php_fpm_base64"
            if test -z "$certificate_directory"; then
                printf '1\n'
            else
                if test -f "$certificate_directory/cert.pem" && test -f "$certificate_directory/key.pem"; then
                    printf '1\n'
                else
                    printf '0\n'
                fi
            fi
            BASH;
    }
}
