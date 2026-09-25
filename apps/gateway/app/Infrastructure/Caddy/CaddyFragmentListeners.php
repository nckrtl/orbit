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
     * these listeners. It sets `listeners_rewritten=1` when it changed a fragment, so a publisher that
     * found its own fragment unchanged still publishes the corrected siblings. Public sites (no
     * `https://` scheme) and Unix socket sites keep their own listeners.
     */
    public function script(string $fragments = '$candidate/fragments'): string
    {
        $routes = $this->routeBind();
        $shared = $this->sharedBind();
        $route = self::RouteFragment;
        $sharedPatterns = implode('|', self::SharedFragments);

        return <<<BASH
            listeners_rewritten=0
            orbit_rewrite_listeners() {
                local fragment=\$1 listeners=\$2 scope=\$3
                local rewritten="\$fragment.orbit-listeners"
                awk -v listeners="\$listeners" -v scope="\$scope" '
                    /^[[:space:]]*bind[[:space:]]/ && (scope == "all" || previous ~ /^[[:space:]]*https:\\/\\/[^[:space:]]+[[:space:]]*[{][[:space:]]*\$/) {
                        match(\$0, /^[[:space:]]*/)
                        print substr(\$0, 1, RLENGTH) "bind " listeners
                        previous = \$0
                        next
                    }
                    { print; if (\$0 !~ /^[[:space:]]*\$/) previous = \$0 }
                ' "\$fragment" > "\$rewritten"
                if ! cmp -s -- "\$fragment" "\$rewritten"; then
                    cat -- "\$rewritten" > "\$fragment"
                    listeners_rewritten=1
                fi
                rm -f -- "\$rewritten"
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
}
