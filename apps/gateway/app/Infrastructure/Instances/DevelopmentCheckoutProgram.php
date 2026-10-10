<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

/**
 * Shell programs that deploy a development default in its own checkout. Each runs as the managed
 * user with the positional arguments home, repository URL, and Instance ID, then its own arguments.
 *
 * Older Gateways served a default from `releases/<name>` through a `current` link. `convert` and
 * `removeReleases` turn that layout back into a plain checkout once; nothing else reads it.
 */
final class DevelopmentCheckoutProgram
{
    /** Exit status when the checkout has uncommitted changes to tracked files. */
    public const int Dirty = 3;

    /** Exit status when the local branch has commits that the target commit does not contain. */
    public const int Diverged = 4;

    /** Exit status when a setup or teardown step holds a lifecycle lock that the program needs. */
    public const int Busy = 75;

    private static function guard(): string
    {
        return <<<'BASH'
            home=$1
            repository=$2
            instance=$3
            shift 3
            test -d "$home" && test ! -L "$home" || exit 1
            test "$(realpath -e -- "$home")" = "$home"
            test -d "$home/.git" && test ! -L "$home/.git" || exit 1
            test "$(stat -c %u -- "$home")" = "$(id -u)"
            test "$(stat -c %u -- "$home/.git")" = "$(id -u)"
            git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false --literal-pathspecs "$@"; }
            test "$(git -C "$home" config --get remote.origin.url)" = "$repository"
            test "$(git -C "$home" rev-parse --git-common-dir)" = .git
            claim="$home/.git/orbit-development-releases-owner"
            state="$home/.git/orbit-development-releases"
            releases="$home/releases"
            current="$home/current"
            identity=$(printf '%s\0%s\0%s\0' "$instance" "$home" "$repository" | base64 --wrap=0)
            guard_file() {
                test -f "$1" && test ! -L "$1" || exit 1
                test "$(stat -c %u -- "$1")" = "$(id -u)"
            }
            # Only the ownership claim proves the old release layout. A `releases` directory alone may be Project content.
            legacy() { [ -e "$claim" ] || [ -L "$claim" ]; }
            guard_legacy() {
                guard_file "$claim"
                test "$(cat -- "$claim")" = "$identity"
                test -d "$state" && test ! -L "$state" || exit 1
                test "$(stat -c %u -- "$state")" = "$(id -u)"
                guard_file "$state/identity"
                test "$(cat -- "$state/identity")" = "$identity"
            }
            # Setup and teardown steps hold this lock, a flock on their checkout directory, while they run.
            # `lock_fd` names the last lock taken, so a process that outlives the program can be started without it.
            lifecycle_lock() {
                local checkout=$1
                test -d "$checkout" && test ! -L "$checkout" || exit 1
                exec {lock_fd}<"$checkout"
                test ! -L "$checkout" || exit 1
                test "$(stat -c '%d:%i' -- "$checkout")" = "$(stat -L -c '%d:%i' -- "/proc/self/fd/$lock_fd")"
                flock -n -x "$lock_fd" || exit 75
            }
            refuse_dirty() {
                local changes
                changes=$(git -C "$home" status --porcelain --untracked-files=no)
                if [ -n "$changes" ]; then
                    printf 'The checkout has uncommitted changes to tracked files:\n%s\n' "$changes" | head -n 21 >&2
                    exit 3
                fi
            }
            # `checkout -B` would drop local commits on the branch that the target commit does not contain.
            refuse_diverged() {
                local branch=$1 commit=$2
                if git -C "$home" rev-parse --verify --quiet "refs/heads/$branch^{commit}" >/dev/null \
                    && ! git -C "$home" merge-base --is-ancestor "refs/heads/$branch" "$commit"; then
                    printf 'The local branch %s has commits that %s does not contain.\n' "$branch" "$commit" >&2
                    exit 4
                fi
            }
            # Checkout leaves untracked and ignored files in a directory that the commit removes, such as the
            # dependencies of a removed package. Remove them first, so a later copy of the checkout as a seed
            # finds only directories the commit has. Every read goes to a file first, so a failed read stops.
            drop_removed_directories() {
                local commit=$1 entries entry parent tree
                entries=$(mktemp "$home/.git/orbit-untracked.XXXXXXXX")
                git -C "$home" ls-files -z --others --directory > "$entries"
                while IFS= read -r -d '' entry; do
                    entry=${entry%/}
                    parent=$(dirname -- "$entry")
                    if [ "$parent" = . ]; then continue; fi
                    tree=$(git -C "$home" ls-tree -d "$commit" -- "$parent")
                    if [ -n "$tree" ]; then continue; fi
                    if [ -d "$home/$entry" ] && [ ! -L "$home/$entry" ]; then chmod -R u+w -- "$home/$entry"; fi
                    rm -rf -- "${home:?}/$entry"
                done < "$entries"
                rm -f -- "$entries"
            }
            BASH;
    }

    /** Fetches the branch and prints its commit, then `LEGACY` while the old release layout remains. */
    public static function target(): string
    {
        return self::guard().<<<'BASH'

            branch=$1
            git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$home" fetch --no-tags -- origin "+refs/heads/$branch:refs/remotes/origin/$branch" >/dev/null
            git -C "$home" rev-parse --verify "refs/remotes/origin/$branch^{commit}"
            if legacy; then printf 'LEGACY\n'; fi
            BASH;
    }

    /** Checks out the commit on the branch in place and prints the commit. */
    public static function checkout(): string
    {
        return self::guard().<<<'BASH'

            branch=$1
            commit=$2
            printf '%s' "$commit" | grep -Eq '^([0-9a-f]{40}|[0-9a-f]{64})$'
            lifecycle_lock "$home"
            refuse_dirty
            refuse_diverged "$branch" "$commit"
            drop_removed_directories "$commit"
            git -C "$home" checkout --quiet --no-track -B "$branch" "$commit"
            test "$(git -C "$home" symbolic-ref --short HEAD)" = "$branch"
            git -C "$home" rev-parse --verify HEAD
            BASH;
    }

    /** Runs one step in the checkout. The command arrives base64-encoded in place of `__COMMAND__`. */
    public static function step(): string
    {
        return self::guard().<<<'BASH'

            timeout_seconds=$1
            lifecycle_lock "$home"
            command_file=$(mktemp "$home/.git/orbit-deploy-step.XXXXXXXX")
            printf '%s' '__COMMAND__' | base64 --decode > "$command_file"
            supervisor=
            watchdog=
            cleanup() {
                status=$?
                trap - EXIT HUP INT TERM
                if [ -n "$supervisor" ]; then
                    kill -TERM -- "-$supervisor" 2>/dev/null || true
                    sleep 0.1
                    kill -KILL -- "-$supervisor" 2>/dev/null || true
                    wait "$supervisor" 2>/dev/null || true
                fi
                if [ -n "$watchdog" ]; then
                    kill "$watchdog" 2>/dev/null || true
                    wait "$watchdog" 2>/dev/null || true
                fi
                rm -f -- "$command_file"
                exit "$status"
            }
            trap cleanup EXIT
            trap 'exit 143' HUP INT TERM
            # Neither the step nor the watchdog keeps the lock, so a daemon or a stray `sleep` cannot refuse the next step.
            setsid bash -eu -c 'cd -- "$1"; exec bash -eu "$2"' bash "$home" "$command_file" {lock_fd}>&- &
            supervisor=$!
            # Wait for setsid before using a negative process-group ID.
            for attempt in $(seq 1 100); do
                group=$(ps -o pgid= -p "$supervisor" | tr -d ' ' || true)
                if [ "$group" = "$supervisor" ]; then break; fi
                if ! kill -0 "$supervisor" 2>/dev/null; then break; fi
                sleep 0.01
            done
            # A separate watchdog session survives loss of the SSH shell's process group.
            setsid bash -c '
                owner=$1
                group=$2
                expires=$((SECONDS + $3))
                while kill -0 "$owner" 2>/dev/null && kill -0 "$group" 2>/dev/null && [ "$SECONDS" -lt "$expires" ]; do sleep 0.1; done
                kill -TERM -- "-$group" 2>/dev/null || true
                sleep 0.1
                kill -KILL -- "-$group" 2>/dev/null || true
            ' bash "$$" "$supervisor" "$timeout_seconds" {lock_fd}>&- &
            watchdog=$!
            if wait "$supervisor"; then status=0; else status=$?; fi
            exit "$status"
            BASH;
    }

    /**
     * Turns an old release layout into a plain checkout at the selected release's commit, and prints
     * that commit. The selected release's untracked and ignored files replace the checkout's: they hold
     * the dependencies and runtime data that the Instance served. The checkout keeps its own environment
     * files, which synchronization wrote, and its other files that the release does not have, except in
     * directories that the commit does not have. `releases/` and `current` stay for `removeReleases`.
     * Every listing is read in full before it is used, so a failed read stops the program before it
     * records the conversion. A repeated run after success changes nothing.
     */
    public static function convert(): string
    {
        return self::guard().<<<'BASH'

            branch=$1
            if ! legacy; then
                test ! -e "$current" && test ! -L "$current" || exit 1
                git -C "$home" rev-parse --verify HEAD
                exit 0
            fi
            guard_legacy
            converted="$state/converted"
            if [ -e "$converted" ] || [ -L "$converted" ]; then
                guard_file "$converted"
                commit=$(cat -- "$converted")
                test "$(git -C "$home" rev-parse --verify HEAD)" = "$commit"
                printf '%s\n' "$commit"
                exit 0
            fi
            test -L "$current"
            link=$(readlink -- "$current")
            case "$link" in releases/*) ;; *) exit 1 ;; esac
            name=${link#releases/}
            printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
            release="$releases/$name"
            test -d "$release" && test ! -L "$release" || exit 1
            test "$(realpath -e -- "$release")" = "$release"
            guard_file "$state/release-$name"
            test "$(cat -- "$state/release-$name")" = "$identity:$name"
            test "$(git -C "$release" rev-parse --path-format=absolute --git-common-dir)" = "$home/.git"
            test "$(git -C "$release" rev-parse --show-toplevel)" = "$release"
            commit=$(git -C "$release" rev-parse --verify HEAD)
            lifecycle_lock "$home"
            refuse_dirty
            refuse_diverged "$branch" "$commit"
            entries=$(mktemp "$state/release-entries.XXXXXXXX")
            git -C "$release" ls-files -z --others --directory > "$entries"
            drop_removed_directories "$commit"
            # The checkout's tracked files are unchanged, so force replaces only untracked files in the way.
            git -C "$home" checkout --quiet --force --no-track -B "$branch" "$commit"
            test "$(git -C "$home" rev-parse --verify HEAD)" = "$commit"
            # Both trees now track the same files, so `--directory` lists the same top-most untracked paths.
            links=$(mktemp "$state/release-links.XXXXXXXX")
            while IFS= read -r -d '' entry; do
                entry=${entry%/}
                target="$home/$entry"
                parent=$(dirname -- "$target")
                test -d "$parent" && test ! -L "$parent" || exit 1
                test "$(realpath -e -- "$parent")" = "$parent"
                case "${entry##*/}" in
                    .env|.env.testing)
                        if [ -f "$target" ] && [ ! -L "$target" ]; then continue; fi
                        ;;
                esac
                if [ -e "$target" ] || [ -L "$target" ]; then
                    if [ -d "$target" ] && [ ! -L "$target" ]; then chmod -R u+w -- "$target"; fi
                    rm -rf -- "$target"
                fi
                cp --reflink=auto -a -- "$release/$entry" "$target"
                # A step in the release may have linked to it by absolute path.
                find -P "$target" -type l -print0 > "$links"
                while IFS= read -r -d '' copied; do
                    raw=$(readlink -- "$copied")
                    case "$raw" in
                        "$release"|"$current") ln -sfn -- "$home" "$copied" ;;
                        "$release"/*) ln -sfn -- "$home/${raw#"$release/"}" "$copied" ;;
                        "$current"/*) ln -sfn -- "$home/${raw#"$current/"}" "$copied" ;;
                    esac
                done < "$links"
            done < "$entries"
            rm -f -- "$entries" "$links"
            staging=$(mktemp "$state/converted.XXXXXXXX")
            printf '%s\n' "$commit" > "$staging"
            mv -T -- "$staging" "$converted"
            printf '%s\n' "$commit"
            BASH;
    }

    /**
     * Removes `current`, every release that carries an ownership receipt, and the layout's state after
     * `convert`. The arguments are the checkouts seeded from this home: while a setup or teardown step
     * runs in one of them, nothing is removed, and the program holds their locks while it removes.
     * A release folder without a receipt stays where it is, and the program prints `KEPT` and its name.
     * The layout's state goes either way, so nothing retries the removal of a folder Orbit does not own.
     */
    public static function removeReleases(): string
    {
        return self::guard().<<<'BASH'

            if ! legacy; then exit 0; fi
            guard_legacy
            guard_file "$state/converted"
            test "$(git -C "$home" rev-parse --verify HEAD)" = "$(cat -- "$state/converted")"
            # A seeded checkout that no longer exists cannot run a step.
            for consumer in "$@"; do
                if [ -e "$consumer" ] || [ -L "$consumer" ]; then
                    lifecycle_lock "$consumer"
                fi
            done
            if [ -e "$current" ] || [ -L "$current" ]; then
                test -L "$current"
                case "$(readlink -- "$current")" in releases/*) ;; *) exit 1 ;; esac
                rm -f -- "$current"
            fi
            owned() {
                local name=$1 intent="$state/intent-$1"
                if [ -f "$state/release-$name" ] && [ ! -L "$state/release-$name" ] && [ "$(cat -- "$state/release-$name")" = "$identity:$name" ]; then return 0; fi
                [ -f "$intent" ] && [ ! -L "$intent" ] || return 1
                case "$(cat -- "$intent")" in "$identity:$name:"*) return 0 ;; esac
                return 1
            }
            # Every listing is read in full before it is used, so a failed read stops the program.
            entries=$(mktemp "$state/entries.XXXXXXXX")
            kept=0
            if [ -e "$releases" ] || [ -L "$releases" ]; then
                test -d "$releases" && test ! -L "$releases" || exit 1
                test "$(realpath -e -- "$releases")" = "$releases"
                find -P "$releases" -mindepth 1 -maxdepth 1 -print0 > "$entries"
                while IFS= read -r -d '' entry; do
                    name=${entry##*/}
                    if ! printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$' || [ -L "$entry" ] || [ ! -d "$entry" ] || ! owned "$name"; then
                        printf 'KEPT\t%s\n' "$name"
                        kept=1
                        continue
                    fi
                    # A read-only cache folder must not stop the removal halfway.
                    chmod -R u+w -- "$entry"
                    rm -rf -- "$entry"
                done < "$entries"
            fi
            # Unregister the removed releases. Other linked worktrees keep their registration.
            if [ -d "$home/.git/worktrees" ] && [ ! -L "$home/.git/worktrees" ]; then
                find -P "$home/.git/worktrees" -mindepth 1 -maxdepth 1 -type d -print0 > "$entries"
                while IFS= read -r -d '' admin; do
                    if [ -L "$admin" ] || [ ! -f "$admin/gitdir" ] || [ -L "$admin/gitdir" ]; then continue; fi
                    registered=$(cat -- "$admin/gitdir")
                    case "$registered" in "$releases"/*/.git) ;; *) continue ;; esac
                    if [ -e "${registered%/.git}" ] || [ -L "${registered%/.git}" ]; then continue; fi
                    rm -rf -- "$admin"
                done < "$entries"
            fi
            if [ "$kept" = 0 ] && [ -e "$releases" ]; then rmdir -- "$releases"; fi
            rm -rf -- "$state"
            rm -f -- "$claim"
            BASH;
    }
}
