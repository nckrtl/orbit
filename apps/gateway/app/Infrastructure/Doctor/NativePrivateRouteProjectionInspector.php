<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\AppDev\DnsRequester;
use App\Domain\Clusters\ClusterState;
use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\PrivateRouteProjectionObservation;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteStateResolver;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Throwable;

final readonly class NativePrivateRouteProjectionInspector implements PrivateRouteProjectionInspector
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private CommandDeadline $deadline,
        private AppDevSiteRepository $sites = new AppDevSiteRepository,
        private RouteStateResolver $placements = new RouteStateResolver,
        private PublicRouteEligibility $eligibility = new PublicRouteEligibility,
        private ClusterRouterDnsSelection $dnsSelection = new ClusterRouterDnsSelection,
        private NodeFirewallRuleCatalog $firewall = new NodeFirewallRuleCatalog,
    ) {}

    public function inspect(AppInstance $instance, Route $route): PrivateRouteProjectionObservation
    {
        $instance->loadMissing(['node.cluster', 'node.roles', 'app']);
        $route->loadMissing(['cluster.routerAssignment.node', 'targets.appInstance.node']);

        try {
            $workload = $this->workloadSite($instance, $route);
            $router = $this->routerNode($instance, $route);
            $routerSite = $router instanceof Node ? $this->routerSite($route, $router) : null;
            $workloadValues = $this->observe($instance->node, $this->workloadCommand($instance, $route, $workload));
            $routerValues = $router instanceof Node && $routerSite instanceof AppDevSite
                ? $this->observe($router, $this->routerCommand($route, $routerSite))
                : ['caddy' => true, 'tls' => true, 'firewall' => true];
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        $expectsFirewall = $this->expectsFirewall($instance->node, $router);

        return new PrivateRouteProjectionObservation(
            routingScopeMatches: $this->routingScopeMatches($instance, $route),
            routerCaddyMatches: $router instanceof Node ? ($routerValues['caddy'] ?? null) : true,
            workloadCaddyMatches: $workloadValues['caddy'] ?? null,
            certificateMatches: $this->combine(
                $workloadValues['tls'] ?? null,
                $router instanceof Node ? ($routerValues['tls'] ?? null) : true,
            ),
            dnsMatches: $workloadValues['dns'] ?? null,
            firewallMatches: $expectsFirewall
                ? $this->combine($workloadValues['firewall'] ?? true, $routerValues['firewall'] ?? true)
                : true,
            laravelUrlMatches: $workloadValues['laravel'] ?? null,
            targetSetMatches: $router instanceof Node
                ? ($routerValues['pool'] ?? $this->targetSetMatches($route, $routerSite))
                : true,
            associationMatches: $this->associationMatches($instance, $route),
        );
    }

    private function targetSetMatches(Route $route, ?AppDevSite $routerSite): bool
    {
        if (! $routerSite instanceof AppDevSite) {
            return $route->targets->count() <= 1;
        }

        $router = $route->cluster?->routerAssignment?->node;
        $expected = $route->targets
            ->map(static function ($row) use ($router): ?string {
                $node = $row->appInstance->node;

                if ($router instanceof Node && $router->is($node)) {
                    return null;
                }

                return is_string($node->lan_ip) && $node->lan_ip !== ''
                    ? $node->lan_ip
                    : $node->wireguard_ip;
            })
            ->filter(static fn (?string $address): bool => is_string($address) && $address !== '')
            ->values()
            ->all();
        $observed = array_values(array_filter(
            $routerSite->proxyAddresses(),
            static fn (string $address): bool => ! str_starts_with($address, 'unix/'),
        ));

        sort($expected);
        sort($observed);

        return $expected === $observed;
    }

    private function associationMatches(AppInstance $instance, Route $route): bool
    {
        $count = $instance->routeTargets()->count();

        return $count === 1 && $instance->routeTargets()->where('route_id', $route->id)->exists();
    }

    private function routingScopeMatches(AppInstance $instance, Route $route): bool
    {
        $placement = $this->placements->forNode($instance->node);

        return $route->node_id === $placement->nodeId && $route->cluster_id === $placement->clusterId;
    }

    private function routerNode(AppInstance $instance, Route $route): ?Node
    {
        $cluster = $route->cluster;
        if ($cluster === null || $cluster->state !== ClusterState::Active) {
            return null;
        }

        $router = $this->eligibility->activeRouter($cluster);

        return $router instanceof Node && ! $router->is($instance->node) ? $router : null;
    }

    private function workloadSite(AppInstance $instance, Route $route): AppDevSite
    {
        $site = $this->sites->forNode($instance->node)->first(
            static fn (AppDevSite $candidate): bool => $candidate->domain === $route->domain
                && $candidate->nodeId === $instance->node_id
                && ! $candidate->isProxy()
                && ! $candidate->publicListener,
        );

        if (! $site instanceof AppDevSite) {
            throw new DoctorInspectionException;
        }

        return $site;
    }

    private function routerSite(Route $route, Node $router): ?AppDevSite
    {
        $site = $this->sites->forNode($router)->first(
            static fn (AppDevSite $candidate): bool => $candidate->domain === $route->domain
                && $candidate->nodeId === $router->id
                && $candidate->isProxy()
                && ! $candidate->publicListener,
        );

        return $site instanceof AppDevSite ? $site : null;
    }

    /** @return array<string, ?bool> */
    private function observe(Node $node, RemoteCommand $command): array
    {
        try {
            $result = $this->ssh->execute(
                $node,
                $command,
                step: 'doctor-private-route',
                errorCode: 'instance.inspection_failed',
                commandTimeout: $this->deadline->cap(30.0),
            );
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        $values = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            if (is_string($key) && is_string($value) && in_array($value, ['0', '1', '2'], true)) {
                $values[$key] = match ($value) {
                    '0' => false,
                    '1' => true,
                    default => null,
                };
            }
        }

        return $values;
    }

    private function workloadCommand(AppInstance $instance, Route $route, AppDevSite $site): RemoteCommand
    {
        $laravel = match ($instance->source_is_laravel) {
            true => '1',
            false => '0',
            default => '2',
        };
        $environment = $instance->usesProductionReleaseLayout()
            ? (string) $instance->production_home
            : $instance->checkout_path;
        $dnsAddress = $this->expectedDnsAddress($instance, $route) ?? '';

        return new RemoteCommand(
            arguments: [
                'bash',
                '-seu',
                '--',
                $site->domain,
                $site->certificateDirectory(),
                $laravel,
                $laravel === '1' ? $this->expectedLaravelUrl($instance, $route) : '',
                $laravel === '1' ? $environment.'/.env' : '',
                $dnsAddress,
            ],
            input: <<<'BASH'
                domain=$1
                certificates=$2
                laravel=$3
                expected_url=$4
                environment=$5
                expected_dns=$6
                live=$(readlink -f /etc/caddy/Caddyfile)
                fragment_dir=$(dirname "$live")/fragments
                if grep -Rqs -- "$domain" "$fragment_dir" 2>/dev/null; then
                    printf 'caddy=1\n'
                else
                    printf 'caddy=0\n'
                fi
                if sudo test -f "$certificates/cert.pem" && sudo test -f "$certificates/key.pem"; then
                    printf 'tls=1\n'
                else
                    printf 'tls=0\n'
                fi
                if [ "$expected_dns" = '' ]; then
                    printf 'dns=2\n'
                else
                    observed=$(getent ahostsv4 "$domain" 2>/dev/null | awk 'NR==1 { print $1 }') || observed=
                    if [ "$observed" = "$expected_dns" ]; then
                        printf 'dns=1\n'
                    elif [ "$observed" = '' ]; then
                        printf 'dns=0\n'
                    else
                        printf 'dns=0\n'
                    fi
                fi
                if sudo ufw status 2>/dev/null | grep -q '^Status: active'; then
                    printf 'firewall=1\n'
                else
                    printf 'firewall=0\n'
                fi
                if [ "$laravel" = 2 ]; then
                    printf 'laravel=2\n'
                elif [ "$laravel" != 1 ]; then
                    printf 'laravel=1\n'
                elif [ ! -f "$environment" ] || [ -L "$environment" ]; then
                    printf 'laravel=0\n'
                else
                    if awk -v expected="$expected_url" '
                        BEGIN { found = 0 }
                        $0 ~ /^APP_URL=/ {
                            found += 1
                            value = substr($0, 9)
                            if (value ~ /^".*"$/) {
                                value = substr(value, 2, length(value) - 2)
                            }
                            if (value != expected) { exit 1 }
                        }
                        END { if (found != 1) exit 1 }
                    ' "$environment"; then
                        printf 'laravel=1\n'
                    else
                        printf 'laravel=0\n'
                    fi
                fi
                BASH,
        );
    }

    private function routerCommand(Route $route, AppDevSite $site): RemoteCommand
    {
        $upstreams = implode("\n", array_values(array_filter(
            $site->proxyAddresses(),
            static fn (string $address): bool => ! str_starts_with($address, 'unix/'),
        )));

        return new RemoteCommand(
            arguments: [
                'bash',
                '-seu',
                '--',
                $route->domain,
                $upstreams,
                $site->certificateDirectory(),
            ],
            input: <<<'BASH'
                domain=$1
                upstreams=$2
                certificates=$3
                live=$(readlink -f /etc/caddy/Caddyfile)
                fragment_dir=$(dirname "$live")/fragments
                pool=1
                if [ "$upstreams" != '' ]; then
                    while IFS= read -r upstream; do
                        [ "$upstream" = '' ] && continue
                        if ! grep -Rqs -- "$upstream" "$fragment_dir" 2>/dev/null; then
                            pool=0
                            break
                        fi
                    done <<EOF
                $upstreams
                EOF
                fi
                if grep -Rqs -- "$domain" "$fragment_dir" 2>/dev/null && [ "$pool" = 1 ]; then
                    printf 'caddy=1\n'
                    printf 'pool=1\n'
                else
                    printf 'caddy=0\n'
                    printf 'pool=0\n'
                fi
                if sudo test -f "$certificates/cert.pem" && sudo test -f "$certificates/key.pem"; then
                    printf 'tls=1\n'
                else
                    printf 'tls=0\n'
                fi
                if sudo ufw status 2>/dev/null | grep -q '^Status: active'; then
                    printf 'firewall=1\n'
                else
                    printf 'firewall=0\n'
                fi
                BASH,
        );
    }

    private function expectedLaravelUrl(AppInstance $instance, Route $route): string
    {
        $stored = $instance->environmentValues()
            ->where('env_key', 'APP_URL')
            ->value('env_value');

        if (! is_string($stored) || $stored === '') {
            return 'https://'.$route->domain;
        }

        return str_replace(
            ['{{app_instance.domain}}', '{{app_instance.environment}}'],
            [$route->domain, $instance->environment],
            $stored,
        );
    }

    private function expectedDnsAddress(AppInstance $instance, Route $route): ?string
    {
        $node = $instance->node;
        if ($route->cluster_id === null) {
            return is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
                ? $node->wireguard_ip
                : null;
        }

        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return null;
        }

        $answers = $this->dnsSelection->answers();
        $override = $answers['overrides'][DnsRequester::registered($node->id, $node->wireguard_ip)->cacheKey()][$route->domain] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $router = $route->cluster?->routerAssignment?->node;
        $address = $router?->wireguard_ip;

        return is_string($address) && $address !== '' ? $address : null;
    }

    private function expectsFirewall(Node $workload, ?Node $router): bool
    {
        foreach ($workload->roles as $assignment) {
            if ($this->firewall->forRole($workload, $assignment->role) !== []) {
                return true;
            }
        }

        return $router instanceof Node && $this->firewall->forRole($router, RoleName::Router) !== [];
    }

    private function combine(?bool $left, ?bool $right): ?bool
    {
        if ($left === null || $right === null) {
            return null;
        }

        return $left && $right;
    }
}
