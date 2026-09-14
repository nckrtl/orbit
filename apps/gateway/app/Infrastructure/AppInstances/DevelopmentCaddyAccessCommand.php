<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AppDev\AppDevSite;
use App\Infrastructure\Ssh\RemoteCommand;
use Illuminate\Support\Collection;

final readonly class DevelopmentCaddyAccessCommand
{
    /** @param Collection<int, AppDevSite> $sites */
    public function command(Collection $sites): RemoteCommand
    {
        $arguments = ['bash', '-seu', '--'];
        foreach ($sites as $site) {
            if ($site->isProxy() || $site->unavailable || $site->environment !== 'development') {
                continue;
            }
            $arguments[] = StoragePath::parse($site->checkoutPath)->value;
            $arguments[] = RelativeWebRoot::validate($site->documentRoot);
        }

        return new RemoteCommand(
            arguments: $arguments,
            input: <<<'BASH'
                set -o pipefail
                checkouts=()
                roots=()
                storage=()
                while [ "$#" -gt 0 ]; do
                    checkout=$1
                    relative_root=$2
                    shift 2
                    test -d "$checkout"
                    test ! -L "$checkout"
                    test "$(realpath -e -- "$checkout")" = "$checkout"
                    test "$(git -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
                    test "$(stat -c %U -- "$checkout")" = "$(id -un)"
                    document_root="$checkout/$relative_root"
                    test -d "$document_root"
                    test ! -L "$document_root"
                    test "$(realpath -e -- "$document_root")" = "$document_root"

                    storage_target=
                    links=$(find -P "$document_root" -type l -print0 | base64 -w0)
                    while IFS= read -r -d '' link; do
                        expected_target="$checkout/storage/app/public"
                        test "$document_root" = "$checkout/public"
                        test "$link" = "$checkout/public/storage"
                        test "$(realpath -e -- "$link")" = "$expected_target"
                        test -d "$expected_target"
                        test "$(realpath -e -- "$expected_target")" = "$expected_target"
                        nested=$(find -P "$expected_target" -type l -print -quit)
                        test -z "$nested"
                        storage_target=$expected_target
                    done < <(printf '%s' "$links" | base64 --decode)
                    checkouts+=("$checkout")
                    roots+=("$document_root")
                    storage+=("$storage_target")
                done

                snapshot=$(mktemp)
                chmod 0600 "$snapshot"
                changed=0
                finish() {
                    result=$?
                    trap - EXIT
                    if [ "$result" != 0 ] && [ "$changed" = 1 ]; then
                        if ! sudo -n setfacl --restore="$snapshot"; then
                            printf 'Caddy access recovery failed; retained ACL snapshot: %s\n' "$snapshot" >&2
                            exit 1
                        fi
                    fi
                    rm -f -- "$snapshot"
                    exit "$result"
                }
                trap finish EXIT
                for checkout in "${checkouts[@]}"; do
                    getfacl -R -P -p -- "$checkout" >> "$snapshot"
                    ancestor=$checkout
                    while [ "$ancestor" != / ]; do
                        ancestor=$(dirname -- "$ancestor")
                        sudo -n getfacl -p -- "$ancestor" >> "$snapshot"
                    done
                done
                changed=1

                # Keep source, Git metadata, and environment files outside the Web root private.
                # Apply every deny before grants so nested Git worktrees retain their own Web roots.
                for checkout in "${checkouts[@]}"; do
                    setfacl -n -P -R -m u:caddy:--- "$checkout"
                    find -P "$checkout" -type d -exec setfacl -m d:u:caddy:--- -- {} +
                done

                for index in "${!checkouts[@]}"; do
                    checkout=${checkouts[$index]}
                    document_root=${roots[$index]}
                    storage_target=${storage[$index]}
                    ancestor=$document_root
                    while [ "$ancestor" != / ]; do
                        ancestor=$(dirname -- "$ancestor")
                        if sudo -n -u caddy python3 -c 'import os, sys; sys.exit(not os.access(sys.argv[1], os.X_OK))' "$ancestor"; then
                            continue
                        fi
                        current_acl=$(sudo -n getfacl -cp -- "$ancestor")
                        caddy_acl=$(printf '%s\n' "$current_acl" | sed -n 's/^user:caddy:\([rwx-]\{3\}\).*$/\1/p')
                        caddy_acl=${caddy_acl:----}
                        mask=$(printf '%s\n' "$current_acl" | sed -n 's/^mask::\([rwx-]\{3\}\).*$/\1/p')
                        if [ -z "$mask" ]; then
                            mask=$(printf '%s\n' "$current_acl" | sed -n 's/^group::\([rwx-]\{3\}\).*$/\1/p')
                        fi
                        sudo -n setfacl -n -m "u:caddy:${caddy_acl%?}x,m::${mask%?}x" -- "$ancestor"
                    done

                    setfacl -P -R -m u:caddy:r-X "$document_root"
                    find -P "$document_root" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                    if [ -n "$storage_target" ]; then
                        setfacl -m u:caddy:--x "$checkout/storage" "$checkout/storage/app"
                        setfacl -P -R -m u:caddy:r-X "$storage_target"
                        find -P "$storage_target" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                    fi
                    sudo -n -u caddy python3 -c 'import os, sys; sys.exit(not os.access(sys.argv[1], os.R_OK | os.X_OK))' "$document_root"
                done
                BASH,
        );
    }
}
