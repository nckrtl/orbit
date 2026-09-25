<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Models\Node;
use Throwable;

/**
 * Renders a Node's whole Caddyfile from committed state (ADR 0141): the Orbit marker line, Orbit's
 * global options, then every site of every site source on that Node. NodeCaddyListeners chooses each
 * site's listener. The renderer reports, rather than renders around, a duplicate address.
 */
final readonly class NodeCaddyfileRenderer
{
    public const string Marker = '# Managed by Orbit: Node Caddy build';

    public const string Wildcard = NodeCaddyListeners::Wildcard;

    /** The named matcher of the clients a site does not admit. */
    public const string OutsideMatcher = '@orbit_outside';

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

        $listeners = NodeCaddyListeners::forSites($node, $sites, app(VpnSettings::class)->subnet());

        $blocks = [];
        $rendered = [];
        $listenAddresses = [];
        /** @var array<string, CaddySite> $addresses */
        $addresses = [];

        foreach ($sites as $site) {
            $bind = $listeners->bind($site->listener, $site->port);

            if ($bind === []) {
                $problems[] = "The {$site->describe()} needs the WireGuard IPv4 address of Node [{$node->name}].";

                continue;
            }

            foreach ($bind as $listener) {
                if ($listener !== self::Wildcard) {
                    $listenAddresses[$listener] = $listener;
                }
            }

            foreach ($this->addresses($site, $bind) as $address) {
                if (array_key_exists($address, $addresses)) {
                    $problems[] = "The {$addresses[$address]->describe()} and the {$site->describe()} both serve {$address}.";

                    continue;
                }

                $addresses[$address] = $site;
            }

            $body = $site->bindPlaceholder === null
                ? $site->body
                : str_replace($site->bindPlaceholder, implode(' ', $bind), $site->body);
            $clients = $listeners->clients($site->listener);

            if ($clients !== null) {
                $body = self::admitOnly($body, $clients);
            }
            $block = "# orbit: {$site->source} {$site->name}".PHP_EOL.rtrim($body).PHP_EOL;
            $blocks[] = $block;
            $rendered[] = ['source' => $site->source, 'name' => $site->name, 'block' => $block];
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
            listenAddresses: array_values($listenAddresses),
            blocks: $rendered,
        );
    }

    /**
     * Aborts every connection from outside the admitted client ranges, right after each network `bind` line of
     * the site. Orbit's global options order `abort` first, so no other handler answers such a client. A unix
     * socket listener serves only local connections and keeps no guard.
     */
    public static function admitOnly(string $body, string $clients): string
    {
        return (string) preg_replace_callback(
            '/^([ \t]*)bind (?!unix\/)[^\n]*$/m',
            static fn (array $match): string => $match[0].PHP_EOL
                .$match[1].self::OutsideMatcher.' not remote_ip '.$clients.PHP_EOL
                .$match[1].'abort '.self::OutsideMatcher,
            $body,
        );
    }

    /**
     * The version directory name. It is a digest of the file, so an unchanged render names the live version.
     */
    public static function version(string $content): string
    {
        return substr(hash('sha256', $content), 0, 32);
    }

    /**
     * @param  list<string>  $bind
     * @return list<string>
     */
    private function addresses(CaddySite $site, array $bind): array
    {
        $addresses = [];

        foreach ($bind as $listener) {
            foreach ($site->hosts as $host) {
                $addresses[] = strtolower($host).":{$site->port} on {$listener}";
            }
        }

        foreach ($site->unixSockets as $socket) {
            $addresses[] = $socket;
        }

        return $addresses;
    }
}
