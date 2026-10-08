<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Ssh\RemoteCommand;
use Illuminate\Support\Collection;

final readonly class DevelopmentCaddyAccessCommand
{
    /**
     * The development sites whose checkout and Web root the command walks.
     *
     * @param  Collection<int, DevelopmentSite>  $sites
     * @return Collection<int, DevelopmentSite>
     */
    public function walkedSites(Collection $sites): Collection
    {
        return $sites
            ->reject(static fn (DevelopmentSite $site): bool => $site->isProxy() || $site->unavailable || $site->environment !== 'development' || $site->checkoutPath === '')
            ->values();
    }

    /** @param Collection<int, DevelopmentSite> $sites */
    public function command(Collection $sites): RemoteCommand
    {
        $arguments = ['bash', '-seu', '--'];
        foreach ($this->walkedSites($sites) as $site) {
            $arguments[] = StoragePath::parse($site->checkoutPath)->value;
            $arguments[] = RelativeWebRoot::validate($site->documentRoot);
            $arguments[] = $site->applicationDirectory();
        }

        return new RemoteCommand(
            arguments: $arguments,
            input: <<<'BASH'
                set -o pipefail
                checkouts=()
                roots=()
                applications=()
                storage=()
                git_directories=()
                while [ "$#" -gt 0 ]; do
                    checkout=$1
                    relative_root=$2
                    application=$3
                    shift 3
                    if [ -L "$checkout" ]; then
                        test "${checkout##*/}" = current
                        home=$(dirname -- "$checkout")
                        test -d "$home/.git" && test ! -L "$home/.git"
                        state="$home/.git/orbit-development-releases"
                        test -d "$state" && test ! -L "$state"
                        test -f "$state/identity" && test ! -L "$state/identity"
                        link=$(readlink -- "$checkout")
                        case "$link" in releases/*) ;; *) exit 1 ;; esac
                        name=${link#releases/}
                        printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                        test -f "$state/release-$name" && test ! -L "$state/release-$name"
                        test "$(cat -- "$state/release-$name")" = "$(cat -- "$state/identity"):$name"
                        resolved=$(realpath -e -- "$checkout")
                        test "$resolved" = "$home/releases/$name"
                        test "$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$resolved" rev-parse --path-format=absolute --git-common-dir)" = "$home/.git"
                        application="$resolved${application#"$checkout"}"
                        checkout=$resolved
                    fi
                    test -d "$checkout"
                    test ! -L "$checkout"
                    test "$(realpath -e -- "$checkout")" = "$checkout"
                    test "$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
                    test "$(stat -c %U -- "$checkout")" = "$(id -un)"
                    git_directory=$(git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" rev-parse --path-format=absolute --git-common-dir)
                    test -d "$git_directory" && test ! -L "$git_directory"
                    test "$(realpath -e -- "$git_directory")" = "$git_directory"
                    test "$(stat -c %U -- "$git_directory")" = "$(id -un)"
                    git_directories+=("$git_directory")
                    document_root="$checkout/$relative_root"
                    test -d "$document_root"
                    test ! -L "$document_root"
                    test "$(realpath -e -- "$document_root")" = "$document_root"

                    storage_target=
                    links=$(find -P "$document_root" -type l -print0 | base64 -w0)
                    while IFS= read -r -d '' link; do
                        expected_target="$application/storage/app/public"
                        test "$document_root" = "$application/public"
                        test "$link" = "$document_root/storage"
                        test "$(realpath -e -- "$link")" = "$expected_target"
                        test -d "$expected_target"
                        test "$(realpath -e -- "$expected_target")" = "$expected_target"
                        nested=$(find -P "$expected_target" -type l -print -quit)
                        test -z "$nested"
                        storage_target=$expected_target
                    done < <(printf '%s' "$links" | base64 --decode)
                    checkouts+=("$checkout")
                    roots+=("$document_root")
                    applications+=("$application")
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
                # Linked worktrees keep shared Git metadata outside their checkout trees.
                # Snapshot its directory ACL before denying traversal; never mutate sibling worktrees.
                for git_directory in "${git_directories[@]}"; do
                    getfacl -p -- "$git_directory" >> "$snapshot"
                done
                changed=1
                for git_directory in "${git_directories[@]}"; do
                    setfacl -n -m u:caddy:--- -- "$git_directory"
                done

                # Keep source, Git metadata, and environment files outside the Web root private.
                # Apply every deny before grants so nested Git worktrees retain their own Web roots.
                for checkout in "${checkouts[@]}"; do
                    setfacl -n -P -R -m u:caddy:--- "$checkout"
                    find -P "$checkout" -type d -exec setfacl -m d:u:caddy:--- -- {} +
                done

                for index in "${!checkouts[@]}"; do
                    checkout=${checkouts[$index]}
                    document_root=${roots[$index]}
                    application=${applications[$index]}
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
                        setfacl -m u:caddy:--x "$application/storage" "$application/storage/app"
                        setfacl -P -R -m u:caddy:r-X "$storage_target"
                        find -P "$storage_target" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                    fi
                    sudo -n -u caddy python3 -c 'import os, sys; sys.exit(not os.access(sys.argv[1], os.R_OK | os.X_OK))' "$document_root"
                done
                BASH,
        );
    }
}
