<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Models\Node;
use Throwable;

/**
 * Renders a Node's whole Caddyfile from committed state (ADR 0141): the Orbit marker line, Orbit's
 * global options, then every site of every site source on that Node. It chooses each site's listener
 * centrally and reports, rather than renders around, a listener conflict or a duplicate address.
 */
final readonly class NodeCaddyfileRenderer
{
    public const string Marker = '# Managed by Orbit: Node Caddy build';

    public const string Wildcard = '0.0.0.0';

    /** @param list<NodeCaddySiteSource> $sources In render order. */
    public function __construct(
        private array $sources,
    ) {}

    public function render(Node $node): NodeCaddyfile
    {
        $problems = [];
        $sites = [];

        foreach ($this->sources as $source) {
            try {
                array_push($sites, ...$source->sites($node));
            } catch (Throwable $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        $wireGuard = is_string($node->wireguard_ip) && $node->wireguard_ip !== '' ? $node->wireguard_ip : null;
        /** @var array<int, CaddySite> $wildcardPorts The first wildcard site on each port. */
        $wildcardPorts = [];

        foreach ($sites as $site) {
            if ($site->listener === CaddyListenerRule::Wildcard && ! array_key_exists($site->port, $wildcardPorts)) {
                $wildcardPorts[$site->port] = $site;
            }
        }

        $blocks = [];
        /** @var array<string, CaddySite> $addresses */
        $addresses = [];

        foreach ($sites as $site) {
            $bind = $this->bind($site, $wildcardPorts, $wireGuard);

            if ($bind === null) {
                $problems[] = "The {$site->describe()} needs the WireGuard IPv4 address of Node [{$node->name}].";

                continue;
            }

            if ($site->listener === CaddyListenerRule::WireGuard && array_key_exists($site->port, $wildcardPorts)) {
                $wildcard = $wildcardPorts[$site->port];
                $problems[] = "The {$site->describe()} binds the WireGuard address on port {$site->port}, which the "
                    ."{$wildcard->describe()} serves on ".self::Wildcard.'. The '
                    ."{$wildcard->source} site would be unreachable over WireGuard.";
            }

            foreach ($this->addresses($site, $bind) as $address) {
                if (array_key_exists($address, $addresses)) {
                    $problems[] = "The {$addresses[$address]->describe()} and the {$site->describe()} both serve {$address}.";

                    continue;
                }

                $addresses[$address] = $site;
            }

            $body = $site->bindPlaceholder === null ? $site->body : str_replace($site->bindPlaceholder, $bind, $site->body);
            $blocks[] = "# orbit: {$site->source} {$site->name}".PHP_EOL.rtrim($body).PHP_EOL;
        }

        $content = self::Marker.PHP_EOL.CaddyGlobalOptions::render();

        if ($blocks !== []) {
            $content .= PHP_EOL.implode(PHP_EOL, $blocks);
        }

        return new NodeCaddyfile(
            nodeName: $node->name,
            content: $content,
            version: self::version($content),
            sites: $sites,
            problems: array_values(array_unique($problems)),
        );
    }

    /**
     * The version directory name. It is a digest of the file, so an unchanged render names the live version.
     */
    public static function version(string $content): string
    {
        return substr(hash('sha256', $content), 0, 32);
    }

    /** @param array<int, CaddySite> $wildcardPorts */
    private function bind(CaddySite $site, array $wildcardPorts, ?string $wireGuard): ?string
    {
        return match ($site->listener) {
            CaddyListenerRule::Wildcard, CaddyListenerRule::Public => self::Wildcard,
            CaddyListenerRule::WireGuard => $wireGuard,
            CaddyListenerRule::Shared => array_key_exists($site->port, $wildcardPorts) ? self::Wildcard : $wireGuard,
        };
    }

    /** @return list<string> */
    private function addresses(CaddySite $site, string $bind): array
    {
        $addresses = array_map(
            static fn (string $host): string => strtolower($host).":{$site->port} on {$bind}",
            $site->hosts,
        );

        foreach ($site->unixSockets as $socket) {
            $addresses[] = $socket;
        }

        return $addresses;
    }
}
