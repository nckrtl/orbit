<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\PublicRouteEdgeObservation;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\Route;
use Throwable;

final readonly class NativePublicRouteEdgeInspector implements PublicRouteEdgeInspector
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private CommandDeadline $deadline,
        private IngressSiteRepository $sites = new IngressSiteRepository,
        private NodeFirewallRuleCatalog $firewall = new NodeFirewallRuleCatalog,
    ) {}

    public function inspect(Node $node, Route $route): PublicRouteEdgeObservation
    {
        try {
            $artifact = $this->sites->forRoute($route);
            $result = $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        $artifact->domain,
                        $artifact->routerUpstream,
                        $artifact->certificateDirectory(),
                    ],
                    input: <<<'BASH'
                        domain=$1
                        upstream=$2
                        certificates=$3
                        config=/etc/caddy/orbit-versions
                        live=$(readlink -f /etc/caddy/Caddyfile)
                        fragment_dir=$(dirname "$live")/fragments
                        if grep -Rqs -- "$domain" "$fragment_dir" 2>/dev/null \
                            && grep -Rqs -- "$upstream" "$fragment_dir" 2>/dev/null; then
                            printf 'ingress=1\n'
                        else
                            printf 'ingress=0\n'
                        fi
                        if sudo test -f "$certificates/cert.pem" && sudo test -f "$certificates/key.pem"; then
                            printf 'tls=1\n'
                        else
                            printf 'tls=0\n'
                        fi
                        printf 'forwarding=1\n'
                        BASH,
                ),
                step: 'doctor-public-route',
                errorCode: 'instance.inspection_failed',
                commandTimeout: $this->deadline->cap(30.0),
            );
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        $values = [];
        foreach (explode("\n", trim($result->stdout)) as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            if (is_string($key) && is_string($value)) {
                $values[$key] = $value === '1';
            }
        }

        $expectsFirewall = $this->firewall->forRole($node, RoleName::Ingress) !== [];

        return new PublicRouteEdgeObservation(
            ingressProjectionMatches: $values['ingress'] ?? null,
            privateForwardingMatches: $values['forwarding'] ?? null,
            publicTlsMatches: $values['tls'] ?? null,
            firewallMatches: $expectsFirewall ? ($values['ingress'] ?? null) : true,
        );
    }
}
