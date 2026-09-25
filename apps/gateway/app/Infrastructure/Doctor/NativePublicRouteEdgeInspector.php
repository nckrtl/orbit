<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\PublicRouteEdgeInspector;
use App\Domain\Doctor\PublicRouteEdgeObservation;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AppDev\AppDevCaddyConfigRenderer;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\AppDev\AppDevSiteRepository;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\Route;
use Throwable;

/**
 * Compares the live public site with the site the App development publisher renders for the Ingress
 * Node, and the Ingress firewall with its managed public HTTP rules.
 *
 * The expected site comes from the same site repository and renderer the publisher uses, so a
 * separate Ingress expects a reverse proxy to the Router, and an Ingress that shares the Router and
 * the workload expects the composed site that serves the Instance directly. The probe reads the live
 * Caddy version as root, because published versions under /etc/caddy/orbit-versions are
 * root:caddy 0750.
 */
final readonly class NativePublicRouteEdgeInspector implements PublicRouteEdgeInspector
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private CommandDeadline $deadline,
        private AppDevSiteRepository $sites = new AppDevSiteRepository,
        private AppDevCaddyConfigRenderer $renderer = new AppDevCaddyConfigRenderer,
        private NodeFirewallRuleCatalog $firewall = new NodeFirewallRuleCatalog,
        private UfwManagedRulesCheck $ufw = new UfwManagedRulesCheck,
        private string $liveCaddyfilePath = '/etc/caddy/Caddyfile',
        private int $defaultForwardingPort = 443,
    ) {}

    public function inspect(Node $node, Route $route): PublicRouteEdgeObservation
    {
        try {
            $site = $this->publicSite($node, $route);
            $result = $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: [
                        'sudo',
                        'bash',
                        '-seu',
                        '--',
                        $site->domain,
                        base64_encode($this->expectedBlock($site)),
                        $site->certificateDirectory(),
                        $this->liveCaddyfilePath,
                        ...$this->forwardingTargets($site),
                    ],
                    input: <<<'BASH'
                        domain=$1
                        expected=$(printf '%s' "$2" | base64 --decode)
                        certificates=$3
                        live=$(readlink -f "$4")
                        # The one live file a Node Caddy build writes must hold the rendered public site. TLS lines
                        # are compared separately below, so a TLS-only difference reports as a TLS mismatch.
                        observed=$(sed '/^[[:space:]]*tls /d' "$live" 2>/dev/null || true)
                        case "$observed" in
                            *"$expected"*) printf 'ingress=1\n' ;;
                            *) printf 'ingress=0\n' ;;
                        esac
                        # Caddy must manage the public certificate: the public site pins no Orbit CA
                        # leaf, and it opts into automation unless the Node leaves automation on.
                        site=$(awk -v start="$domain {" '
                            $0 == start { inside = 1 }
                            inside { print }
                            inside && $0 == "}" { exit }
                        ' "$live" 2>/dev/null || true)
                        if [ -n "$site" ] \
                            && ! grep -qs -- "tls $certificates/cert.pem" "$live" \
                            && { printf '%s\n' "$site" | grep -Eq '^[[:space:]]+tls force_automate$' \
                                || ! grep -Eqs '^[[:space:]]*auto_https[[:space:]]+(disable_certs|off)$' "$live"; }; then
                            printf 'tls=1\n'
                        else
                            printf 'tls=0\n'
                        fi
                        # The Ingress must reach every private address the public site forwards to. A composed
                        # site that serves the Instance directly forwards nowhere and always matches.
                        forwarding=1
                        for target in "${@:5}"; do
                            if ! timeout 1 bash -c 'echo >"/dev/tcp/${1%:*}/${1##*:}"' _ "$target" 2>/dev/null; then
                                forwarding=0
                            fi
                        done
                        printf 'forwarding=%s\n' "$forwarding"
                        BASH,
                ),
                step: 'doctor-public-route',
                errorCode: 'instance.inspection_failed',
                commandTimeout: $this->deadline->cap(30.0),
            );
            $firewallMatches = $this->firewallMatches($node);
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

        return new PublicRouteEdgeObservation(
            ingressProjectionMatches: $values['ingress'] ?? null,
            privateForwardingMatches: $values['forwarding'] ?? null,
            publicTlsMatches: $values['tls'] ?? null,
            firewallMatches: $firewallMatches,
        );
    }

    private function publicSite(Node $node, Route $route): AppDevSite
    {
        $site = $this->sites->forNode($node)->first(
            static fn (AppDevSite $candidate): bool => $candidate->publicListener
                && $candidate->domain === $route->domain,
        );

        if (! $site instanceof AppDevSite) {
            throw new DoctorInspectionException;
        }

        return $site;
    }

    /**
     * The `host:port` pairs the public site forwards to over TCP. Caddy proxies each address over HTTPS, so an
     * address without a port uses the HTTPS port. Unix socket upstreams stay on the Node and are not forwarding
     * targets.
     *
     * @return list<string>
     */
    private function forwardingTargets(AppDevSite $site): array
    {
        $targets = [];

        foreach ($site->proxyAddresses() as $address) {
            if (str_starts_with($address, 'unix/')) {
                continue;
            }

            $host = parse_url("https://{$address}", PHP_URL_HOST);
            $port = parse_url("https://{$address}", PHP_URL_PORT);

            if (! is_string($host) || $host === '') {
                throw new DoctorInspectionException;
            }

            $targets[] = trim($host, '[]').':'.(is_int($port) ? $port : $this->defaultForwardingPort);
        }

        return $targets;
    }

    /** The rendered site without its trailing newline and without TLS lines. */
    private function expectedBlock(AppDevSite $site): string
    {
        $lines = explode("\n", rtrim($this->renderer->render(collect([$site])), "\n"));

        return implode("\n", array_filter(
            $lines,
            static fn (string $line): bool => preg_match('/^\s*tls /', $line) !== 1,
        ));
    }

    /** The Ingress firewall matches when every managed public HTTP rule is present exactly. */
    private function firewallMatches(Node $node): bool
    {
        $rules = $this->firewall->forRole($node, RoleName::Ingress);

        if ($rules === []) {
            return true;
        }

        $result = $this->ssh->execute(
            $node,
            new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']),
            step: 'doctor-public-route-firewall',
            errorCode: 'instance.inspection_failed',
            commandTimeout: $this->deadline->cap(30.0),
        );

        return $this->ufw->matches($result, $rules);
    }
}
