<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy;

final readonly class CaddyGlobalOptions
{
    public static function render(): string
    {
        return <<<'CADDY'
            {
                auto_https disable_certs
            }
            CADDY.PHP_EOL;
    }

    /**
     * Bash that defines `refuse_carried_global_options <candidate> <source_main>`. It refuses
     * a candidate whose carried-forward fragment opens its own global options block. Caddy
     * allows one global block and requires it first, so Orbit's block must stay the only one.
     */
    public static function conflictGuard(): string
    {
        return <<<'BASH'
            refuse_carried_global_options() {
                local candidate=$1 source_main=$2 fragment fragment_name carried_options carried_source conflict=0
                for fragment in "$candidate"/fragments/*.caddy; do
                    [ -e "$fragment" ] || continue
                    if ! carried_options=$(awk '
                        /^[ \t]*(#|$)/ { next }
                        !started { if ($1 != "{") exit 1; started = 1; depth = 1; next }
                        $1 == "}" && depth == 1 { exit 0 }
                        depth == 1 { names = names (names == "" ? "" : ", ") $1 }
                        $NF == "{" { depth++ }
                        $1 == "}" { depth-- }
                        END { if (!started) exit 1; print names }
                    ' "$fragment"); then
                        continue
                    fi
                    fragment_name=$(basename "$fragment")
                    carried_source=$(dirname "$source_main")/fragments/$fragment_name
                    if [ ! -f "$carried_source" ] && [ "$fragment_name" = 00-unmanaged.caddy ]; then
                        carried_source=$(dirname "$source_main")/fragments/unmanaged.caddy
                    fi
                    if [ ! -f "$carried_source" ]; then
                        carried_source=$source_main
                    fi
                    printf 'Caddy fragment %s opens its own global options block (%s). Orbit writes the only global options block. Remove that block from %s, then publish again.\n' \
                        "$fragment_name" "${carried_options:-empty}" "$carried_source" >&2
                    conflict=1
                done
                if [ "$conflict" = 1 ]; then
                    exit 1
                fi
            }

            BASH;
    }
}
