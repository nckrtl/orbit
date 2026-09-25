<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy;

use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\NodeCaddyListeners;
use InvalidArgumentException;

/**
 * The listeners a fragment publisher writes on one Node, from the Node Caddy build's rule. A publisher
 * binds its own site with them and rewrites the `bind` lines of the other Orbit fragments it carries, so
 * one publication leaves every fragment on the rule, whatever an earlier publisher wrote.
 */
final readonly class CaddyFragmentListeners
{
    /** The fragment that holds the Node's Route sites. Its private `https://` sites use the first-row rule. */
    public const string RouteFragment = 'app-dev.caddy';

    /** Fragments that hold one shared site each. */
    public const array SharedFragments = ['websocket.caddy', 'analytics.caddy', 'proxycli.caddy'];

    /**
     * @param  non-empty-list<string>  $routes  The listeners of private Route sites.
     * @param  non-empty-list<string>  $shared  The listeners of the `websocket`, `analytics`, and ProxyCli sites.
     */
    public function __construct(
        public array $routes,
        public array $shared,
    ) {
        foreach ([...$routes, ...$shared] as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException("Caddy listener [{$address}] is not an IPv4 address.");
            }
        }
    }

    /**
     * Every shared and Route site listens on port 443. A Node without a WireGuard address keeps the
     * wildcard listener: no publisher reaches such a Node, because Orbit connects over WireGuard.
     */
    public static function from(NodeCaddyListeners $listeners): self
    {
        return new self(
            routes: $listeners->bind(CaddyListenerRule::Wildcard, 443) ?: [NodeCaddyListeners::Wildcard],
            shared: $listeners->bind(CaddyListenerRule::Shared, 443) ?: [NodeCaddyListeners::Wildcard],
        );
    }

    public function routeBind(): string
    {
        return implode(' ', $this->routes);
    }

    public function sharedBind(): string
    {
        return implode(' ', $this->shared);
    }

    /**
     * Shell code that rewrites the `bind` lines of the Route and shared fragments in `$fragments` to
     * these listeners. It rewrites a fragment only when one of its `bind` lines differs, so an unchanged
     * fragment keeps its exact bytes, and it sets `listeners_rewritten=1` when it rewrote one. Public
     * sites (no `https://` scheme) and Unix socket sites keep their own listeners. It also defines
     * `orbit_require_listen_addresses`, which a publisher calls before it swaps the live Caddyfile. It
     * refuses a listener that is not an address on the Node, as the Node Caddy build does.
     */
    public function script(string $fragments = '$candidate/fragments'): string
    {
        $routes = $this->routeBind();
        $shared = $this->sharedBind();
        $route = self::RouteFragment;
        $sharedPatterns = implode('|', self::SharedFragments);
        $addresses = implode(' ', $this->listenAddresses());

        return <<<BASH
            listeners_rewritten=0
            orbit_rewrite_listeners() {
                local fragment=\$1 listeners=\$2 scope=\$3
                local rewritten="\$fragment.orbit-listeners" changed
                changed=\$(awk -v listeners="\$listeners" -v scope="\$scope" -v out="\$rewritten" '
                    /^[[:space:]]*bind[[:space:]]/ && (scope == "all" || previous ~ /^[[:space:]]*https:\\/\\/[^[:space:]]+[[:space:]]*[{][[:space:]]*\$/) {
                        match(\$0, /^[[:space:]]*/)
                        line = substr(\$0, 1, RLENGTH) "bind " listeners
                        if (line != \$0) changed++
                        print line > out
                        previous = \$0
                        next
                    }
                    { print > out; if (\$0 !~ /^[[:space:]]*\$/) previous = \$0 }
                    END { print changed + 0 }
                ' "\$fragment")
                if [ "\$changed" != 0 ]; then
                    cat -- "\$rewritten" > "\$fragment"
                    listeners_rewritten=1
                fi
                rm -f -- "\$rewritten"
            }
            orbit_require_listen_addresses() {
                local present address
                present=\$(ip -o -4 addr show 2>/dev/null | awk '{ split(\$4, parts, "/"); print parts[1] }' || true)
                for address in {$addresses}; do
                    if ! printf '%s\\n' "\$present" | grep -Fxq -- "\$address"; then
                        printf 'Caddy would bind %s, which is not an address on this Node. Correct the stored WireGuard or LAN address of the Node, then publish again.\\n' "\$address" >&2
                        exit 1
                    fi
                done
            }
            for listener_fragment in "{$fragments}"/*.caddy; do
                if [ ! -f "\$listener_fragment" ] || [ -L "\$listener_fragment" ]; then
                    continue
                fi
                case "\$(basename "\$listener_fragment")" in
                    {$route}) orbit_rewrite_listeners "\$listener_fragment" '{$routes}' https ;;
                    {$sharedPatterns}) orbit_rewrite_listeners "\$listener_fragment" '{$shared}' all ;;
                esac
            done
            BASH;
    }

    /**
     * Shell function `orbit_fragments_unchanged CANDIDATE LIVE`. It succeeds when both directories hold the
     * same `.caddy` files with the same bytes, so a publisher leaves an identical live version alone.
     */
    public static function comparison(): string
    {
        return <<<'BASH'
            orbit_fragments_unchanged() {
                local candidate=$1 live=$2 fragment
                [ -d "$live" ] || return 1
                for fragment in "$candidate"/*.caddy; do
                    [ -e "$fragment" ] || continue
                    cmp -s -- "$fragment" "$live/$(basename "$fragment")" || return 1
                done
                for fragment in "$live"/*.caddy; do
                    [ -e "$fragment" ] || continue
                    [ -e "$candidate/$(basename "$fragment")" ] || return 1
                done
            }
            BASH;
    }

    /** @return list<string> The specific addresses these listeners bind, which must exist on the Node. */
    public function listenAddresses(): array
    {
        return array_values(array_unique(array_filter(
            [...$this->routes, ...$this->shared],
            static fn (string $address): bool => $address !== NodeCaddyListeners::Wildcard,
        )));
    }
}
