<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

/** The Git repository never moves. Only managed, detached worktrees are disposable. */
final class DevelopmentReleaseProgram
{
    public static function guard(): string
    {
        return <<<'BASH'
            home=$1
            repository=$2
            instance=$3
            shift 3
            test -d "$home"
            test ! -L "$home"
            test "$(realpath -e -- "$home")" = "$home"
            test -d "$home/.git"
            test ! -L "$home/.git"
            test "$(stat -c %u -- "$home")" = "$(id -u)"
            test "$(stat -c %u -- "$home/.git")" = "$(id -u)"
            git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false "$@"; }
            test "$(git -C "$home" config --get remote.origin.url)" = "$repository"
            test "$(git -C "$home" rev-parse --git-common-dir)" = .git
            state="$home/.git/orbit-development-releases"
            releases="$home/releases"
            current="$home/current"
            marker="$state/identity"
            claim="$home/.git/orbit-development-releases-owner"
            identity=$(printf '%s\0%s\0%s\0' "$instance" "$home" "$repository" | base64 --wrap=0)
            guard_file() {
                test -f "$1" && test ! -L "$1" || exit 1
                test "$(stat -c %u -- "$1")" = "$(id -u)"
            }
            write_receipt() {
                local path=$1 contents=$2 staging
                if [ -e "$path" ] || [ -L "$path" ]; then
                    guard_file "$path"
                    test "$(cat -- "$path")" = "$contents"
                    return
                fi
                staging=$(mktemp "$home/.git/orbit-development-receipt.XXXXXXXX")
                printf '%s\n' "$contents" > "$staging"
                chmod 0600 -- "$staging"
                # An exclusive hard link publishes complete contents, never a partial receipt.
                ln -- "$staging" "$path"
                rm -f -- "$staging"
            }
            guard_layout() {
                test -d "$state" && test ! -L "$state" || exit 1
                test "$(stat -c %u -- "$state")" = "$(id -u)"
                guard_file "$marker"
                test "$(cat -- "$marker")" = "$identity"
                test -d "$releases" && test ! -L "$releases" || exit 1
                test "$(realpath -e -- "$releases")" = "$releases"
                test "$(stat -c %u -- "$releases")" = "$(id -u)"
            }
            recover_cache_branch() {
                local journal="$state/cache-branch-$name" value cached_commit cached_branch cache_fd actual_ref ref
                if [ ! -e "$journal" ] && [ ! -L "$journal" ]; then return; fi
                guard_file "$marker"
                test "$(cat -- "$marker")" = "$identity"
                guard_file "$state/release-$name"
                test "$(cat -- "$state/release-$name")" = "$identity:$name"
                guard_file "$journal"
                exec {cache_fd}< "$journal"
                test "$(stat -c '%d:%i' -- "$journal")" = "$(stat -L -c '%d:%i' -- "/proc/self/fd/$cache_fd")"
                # A surviving cache command owns this transition. Never detach its live checkout.
                flock -n -x "$cache_fd" || exit 1
                value=$(cat -- "$journal")
                case "$value" in "$identity:$name:"*) ;; *) exit 1 ;; esac
                value=${value#"$identity:$name:"}
                cached_commit=${value%%:*}
                cached_branch=${value#*:}
                printf '%s' "$cached_commit" | grep -Eq '^([0-9a-f]{40}|[0-9a-f]{64})$'
                test "$cached_branch" = "orbit-cache-$name"
                test -L "$current"
                test "$(realpath -e -- "$current")" != "$release"
                test "$(git -C "$release" rev-parse HEAD)" = "$cached_commit"
                ref="refs/heads/$cached_branch"
                actual_ref=$(git -C "$release" symbolic-ref -q HEAD) || actual_ref=
                test -z "$actual_ref" || test "$actual_ref" = "$ref"
                if git -C "$home" show-ref --verify --quiet "$ref"; then
                    test "$(git -C "$home" rev-parse "$ref")" = "$cached_commit"
                    test "$(git -C "$home" reflog show -1 --format=%gs "$ref")" = "orbit cache $identity:$name:$cached_commit"
                    git -C "$release" checkout --quiet --detach --force "$cached_commit"
                    git -C "$home" branch -D -- "$cached_branch" >/dev/null
                else
                    test -z "$actual_ref"
                fi
                rm -f -- "$journal"
                exec {cache_fd}<&-
            }
            guard_worktree() {
                name=$1
                printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                release="$releases/$name"
                test -d "$release" && test ! -L "$release" || exit 1
                test "$(stat -c %u -- "$release")" = "$(id -u)"
                guard_file "$release/.git"
                test "$(realpath -e -- "$release")" = "$release"
                common=$(git -C "$release" rev-parse --path-format=absolute --git-common-dir)
                test "$common" = "$home/.git"
                git_directory=$(git -C "$release" rev-parse --absolute-git-dir)
                case "$git_directory" in "$home/.git/worktrees/"*) ;; *) exit 1 ;; esac
                test -d "$git_directory" && test ! -L "$git_directory" || exit 1
                test "$(stat -c %u -- "$git_directory")" = "$(id -u)"
                guard_file "$git_directory/gitdir"
                test "$(cat -- "$git_directory/gitdir")" = "$release/.git"
                test "$(git -C "$release" rev-parse --show-toplevel)" = "$release"
                recover_cache_branch
                if git -C "$release" symbolic-ref -q HEAD >/dev/null; then exit 1; fi
            }
            guard_release() {
                guard_worktree "$1"
                release_marker="$state/release-$name"
                guard_file "$release_marker"
                test "$(cat -- "$release_marker")" = "$identity:$name"
            }
            inspect_release() {
                name=$1
                printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                release="$releases/$name"
                test -d "$release" && test ! -L "$release" || exit 1
                test "$(stat -c %u -- "$release")" = "$(id -u)"
                test "$(realpath -e -- "$release")" = "$release"
                guard_file "$release/.git"
                guard_file "$state/release-$name"
                test "$(cat -- "$state/release-$name")" = "$identity:$name"
                broken_release=0
                git_directory="$home/.git/worktrees/$name"
                # Only a missing, exactly named admin entry with intact ownership is skippable.
                # Do not turn a general guard failure into permission to ignore an unknown path.
                if [ "$(cat -- "$release/.git")" = "gitdir: $git_directory" ] && [ ! -e "$git_directory" ] && [ ! -L "$git_directory" ]; then
                    test -d "$home/.git/worktrees" && test ! -L "$home/.git/worktrees" || exit 1
                    test "$(stat -c %u -- "$home/.git/worktrees")" = "$(id -u)"
                    test "$(realpath -e -- "$home/.git/worktrees")" = "$home/.git/worktrees"
                    if git -C "$release" rev-parse --absolute-git-dir >/dev/null 2>&1; then exit 1; else git_status=$?; fi
                    test "$git_status" = 128
                    broken_release=1
                    printf 'SKIPPED_BROKEN_RELEASE\t%s\tmissing-worktree-admin\n' "$name" >&2
                else
                    guard_release "$name"
                fi
            }
            recover_intents() {
                local intent value intended_name intended_commit
                while IFS= read -r -d '' intent; do
                    guard_file "$intent"
                    value=$(cat -- "$intent")
                    case "$value" in "$identity:"*) ;; *) exit 1 ;; esac
                    value=${value#"$identity:"}
                    intended_name=${value%%:*}
                    intended_commit=${value#*:}
                    printf '%s' "$intended_name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                    printf '%s' "$intended_commit" | grep -Eq '^([0-9a-f]{40}|[0-9a-f]{64})$'
                    test "$intent" = "$state/intent-$intended_name"
                    if [ -e "$releases/$intended_name" ] || [ -L "$releases/$intended_name" ]; then
                        guard_worktree "$intended_name"
                        test "$(git -C "$release" rev-parse HEAD)" = "$intended_commit"
                        write_receipt "$state/release-$intended_name" "$identity:$intended_name"
                    fi
                    rm -f -- "$intent"
                done < <(find -P "$state" -mindepth 1 -maxdepth 1 -name 'intent-*' -print0)
            }
            create_release() {
                local intended_name=$1 intended_commit=$2
                test ! -e "$releases/$intended_name" && test ! -L "$releases/$intended_name" || exit 1
                write_receipt "$state/intent-$intended_name" "$identity:$intended_name:$intended_commit"
                git -C "$home" worktree add --detach -- "$releases/$intended_name" "$intended_commit" >/dev/null
                guard_worktree "$intended_name"
                write_receipt "$state/release-$intended_name" "$identity:$intended_name"
                rm -f -- "$state/intent-$intended_name"
            }
            selected_name() {
                test -L "$current"
                link=$(readlink -- "$current")
                case "$link" in releases/*) ;; *) exit 1 ;; esac
                selected=${link#releases/}
                guard_release "$selected"
                printf '%s' "$selected"
            }
            copy_tree() {
                local source=$1 destination=$2 entry base link source_link raw target mapped relative
                test -d "$source" && test ! -L "$source" || return 1
                test -d "$destination" && test ! -L "$destination" || return 1
                # Copy top-level entries, including dotfiles, without traversing source links.
                while IFS= read -r -d '' entry; do
                    base=${entry##*/}
                    case "$base" in .git|releases|current) continue ;; esac
                    cp --reflink=always -a -- "$entry" "$destination/" || return 1
                done < <(find -P "$source" -mindepth 1 -maxdepth 1 -print0)
                # Internal absolute links must not keep pointing into the old seed after copying.
                while IFS= read -r -d '' link; do
                    source_link="$source/${link#"$destination/"}"
                    target=$(realpath -m -- "$source_link") || return 1
                    case "$target" in
                        "$source") mapped="$destination" ;;
                        "$source"/*) mapped="$destination/${target#"$source/"}" ;;
                        *) return 1 ;;
                    esac
                    raw=$(readlink -- "$source_link") || return 1
                    case "$raw" in
                        /*)
                            relative=$(realpath -m --relative-to="$(dirname -- "$link")" -- "$mapped") || return 1
                            rm -f -- "$link" || return 1
                            ln -s -- "$relative" "$link" || return 1
                            ;;
                    esac
                done < <(find -P "$destination" -type l -print0)
            }
            guard_links() {
                while IFS= read -r -d '' link; do
                    resolved=$(realpath -m -- "$link")
                    case "$resolved" in "$release"|"$release"/*) ;; *) exit 1 ;; esac
                done < <(find -P "$release" -type l -print0)
            }
            receipt() {
                guard_release "$1"
                printf '%s\t%s\n' "$name" "$(git -C "$release" rev-parse --verify HEAD)"
            }
            recover_publication() {
                local temporary="$home/.orbit-current-$instance.tmp" publication="$state/publication" value pending
                if [ -e "$publication" ] || [ -L "$publication" ]; then
                    guard_file "$publication"
                    value=$(cat -- "$publication")
                    case "$value" in "$identity:"*) ;; *) exit 1 ;; esac
                    pending=${value#"$identity:"}
                    printf '%s' "$pending" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                    if [ -e "$temporary" ] || [ -L "$temporary" ]; then
                        test -L "$temporary"
                        test "$(stat -c %u -- "$temporary")" = "$(id -u)"
                        test "$(readlink -- "$temporary")" = "releases/$pending"
                        rm -f -- "$temporary"
                    fi
                    rm -f -- "$publication"
                else
                    test ! -e "$temporary" && test ! -L "$temporary" || exit 1
                fi
            }
            switch_current() {
                local next=$1 temporary="$home/.orbit-current-$instance.tmp"
                recover_publication
                guard_release "$next"
                write_receipt "$state/publication" "$identity:$next"
                ln -s "releases/$next" "$temporary"
                mv -Tf -- "$temporary" "$current"
                rm -f -- "$state/publication"
            }
            BASH;
    }

    public static function initialize(): string
    {
        return self::guard().<<<'BASH'

            if [ ! -e "$claim" ] && [ ! -L "$claim" ]; then
                if [ -e "$state" ] || [ -L "$state" ]; then
                    # Adopt only a fully marked earlier layout, never an unclaimed partial directory.
                    guard_layout
                else
                    test ! -e "$releases" && test ! -L "$releases" || exit 1
                    test ! -e "$current" && test ! -L "$current" || exit 1
                fi
                write_receipt "$claim" "$identity"
            fi
            guard_file "$claim"
            test "$(cat -- "$claim")" = "$identity"
            if [ ! -e "$state" ] && [ ! -L "$state" ]; then mkdir -m 0700 -- "$state"; fi
            test -d "$state" && test ! -L "$state" || exit 1
            test "$(stat -c %u -- "$state")" = "$(id -u)"
            if [ ! -e "$marker" ] && [ ! -L "$marker" ]; then
                test -z "$(find -P "$state" -mindepth 1 -maxdepth 1 -print -quit)"
            fi
            write_receipt "$marker" "$identity"
            if [ ! -e "$releases" ] && [ ! -L "$releases" ]; then mkdir -- "$releases"; fi
            guard_layout
            recover_intents
            recover_publication
            if [ -e "$current" ] || [ -L "$current" ]; then
                selected_name >/dev/null
                exit 0
            fi
            # The repository and all existing linked-worktree administrative paths stay put.
            initial=initial
            if [ -e "$releases/$initial" ]; then
                guard_release "$initial"
                git -C "$home" worktree remove --force -- "$release"
            fi
            rm -f -- "$state/release-$initial"
            create_release "$initial" "$(git -C "$home" rev-parse HEAD)"
            copy_tree "$home" "$releases/$initial"
            guard_release "$initial"
            guard_links
            switch_current "$initial"
            BASH;
    }

    public static function target(): string
    {
        return self::guard().<<<'BASH'

            guard_layout
            branch=$1
            git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$home" fetch --no-tags -- origin "+refs/heads/$branch:refs/remotes/origin/$branch" >/dev/null
            git -C "$home" rev-parse --verify "refs/remotes/origin/$branch^{commit}"
            BASH;
    }

    public static function selected(): string
    {
        return self::guard().<<<'BASH'

            guard_layout
            selected=$(selected_name)
            receipt "$selected"
            BASH;
    }

    public static function releases(): string
    {
        return self::guard().<<<'BASH'

            guard_layout
            selected=$(selected_name)
            printf 'SELECTED\t%s\n' "$selected"
            while IFS= read -r -d '' entry; do
                inspect_release "${entry##*/}"
                if [ "$broken_release" = 1 ]; then continue; fi
                printf 'RELEASE\t%s\n' "$name"
            done < <(find -P "$releases" -mindepth 1 -maxdepth 1 -print0)
            BASH;
    }

    public static function prepare(): string
    {
        return self::guard().<<<'BASH'

            guard_layout
            candidate=$1
            commit=$2
            printf '%s' "$candidate" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
            printf '%s' "$commit" | grep -Eq '^([0-9a-f]{40}|[0-9a-f]{64})$'
            source_name=$(selected_name)
            source="$releases/$source_name"
            test ! -e "$releases/$candidate" && test ! -L "$releases/$candidate" || exit 1
            test ! -e "$state/release-$candidate" && test ! -L "$state/release-$candidate" || exit 1
            shift 2
            create_release "$candidate" "$(git -C "$source" rev-parse HEAD)"
            copy_tree "$source" "$releases/$candidate"
            git -C "$releases/$candidate" reset --hard "$commit" >/dev/null
            # Explicit synchronization and Route APP_URL writes target this Instance's stable environment
            # files: at the checkout root, in the default application directory, and in each directory
            # that a Route with a web root serves.
            copy_environment() {
                local from=$1 to=$2 environment
                for environment in .env .env.testing; do
                    if [ -e "$from/$environment" ] || [ -L "$from/$environment" ]; then
                        test -f "$from/$environment" && test ! -L "$from/$environment" || exit 1
                        test ! -d "$to/$environment" || exit 1
                        rm -f -- "$to/$environment"
                        cp --reflink=always -a -- "$from/$environment" "$to/$environment"
                    fi
                done
            }
            copy_environment "$home" "$releases/$candidate"
            for directory in "$@"; do
                printf '%s' "$directory" | grep -Eq '^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$'
                case "/$directory/" in */./*|*/../*) exit 1 ;; esac
                from="$home/$directory"
                to="$releases/$candidate/$directory"
                # A directory missing from the stable home or from the new commit has nothing to carry.
                if [ ! -d "$from" ] || [ ! -d "$to" ]; then continue; fi
                test "$(realpath -e -- "$from")" = "$from"
                test "$(realpath -e -- "$to")" = "$to"
                copy_environment "$from" "$to"
            done
            guard_release "$candidate"
            guard_links
            receipt "$candidate"
            BASH;
    }

    public static function activate(): string
    {
        return self::guard().<<<'BASH'

            guard_layout
            candidate=$1
            expected_commit=$2
            guard_release "$candidate"
            test "$(git -C "$release" rev-parse HEAD)" = "$expected_commit"
            guard_links
            test ! -e "$state/snapshot-$candidate" && test ! -L "$state/snapshot-$candidate"
            previous=$(selected_name)
            guard_release "$candidate"
            test ! -e "$state/previous-$candidate" && test ! -L "$state/previous-$candidate"
            printf '%s\n' "$previous" > "$state/previous-$candidate"
            switch_current "$candidate"
            receipt "$candidate"
            BASH;
    }

    public static function step(): string
    {
        return self::guard().<<<'BASH'

            guard_layout
            candidate=$1
            best_effort=$2
            timeout_seconds=$3
            step_name=$4
            guard_release "$candidate"
            selected=$(selected_name)
            test "$candidate" != "$selected"
            guard_release "$candidate"
            restored="$state/restored-$candidate"
            rm -f -- "$restored"
            snapshot="$state/snapshot-$candidate"
            test ! -e "$snapshot" && test ! -L "$snapshot"
            if [ "$best_effort" = 1 ]; then
                mkdir -m 0700 -- "$snapshot"
                copy_tree "$release" "$snapshot"
            fi
            command_file=$(mktemp "$state/step.XXXXXXXX")
            printf '%s' '__COMMAND__' | base64 --decode > "$command_file"
            chmod 0600 -- "$command_file"
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
                if [ "$best_effort" = 1 ]; then
                    if [ "$status" -ne 0 ]; then
                        guard_layout
                        guard_release "$candidate"
                        test "$(selected_name)" != "$candidate"
                        test -d "$snapshot" && test ! -L "$snapshot"
                        test "$(realpath -e -- "$snapshot")" = "$snapshot"
                        # Restore only the candidate. The live release was never a write target.
                        while IFS= read -r -d '' entry; do
                            if [ "${entry##*/}" != .git ]; then rm -rf -- "$entry"; fi
                        done < <(find -P "$release" -mindepth 1 -maxdepth 1 -print0)
                        copy_tree "$snapshot" "$release" || exit 1
                    fi
                    rm -rf -- "$snapshot"
                fi
                rm -f -- "$command_file"
                if [ "$best_effort" = 1 ] && [ "$status" -ne 0 ]; then
                    printf '%s\t%s\n' "$step_name" "$status" > "$restored"
                fi
                exit "$status"
            }
            trap cleanup EXIT
            trap 'exit 143' HUP INT TERM
            setsid bash -eu -c 'cd -- "$1"; exec bash -eu "$2"' bash "$release" "$command_file" &
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
            ' bash "$$" "$supervisor" "$timeout_seconds" &
            watchdog=$!
            if wait "$supervisor"; then status=0; else status=$?; fi
            exit "$status"
            BASH;
    }

    public static function restored(): string
    {
        return self::guard().<<<'BASH'

            guard_layout
            candidate=$1
            step_name=$2
            status=$3
            guard_release "$candidate"
            restored="$state/restored-$candidate"
            test -f "$restored" && test ! -L "$restored"
            test "$(cat -- "$restored")" = "$(printf '%s\t%s' "$step_name" "$status")"
            test ! -e "$state/snapshot-$candidate" && test ! -L "$state/snapshot-$candidate"
            BASH;
    }

    public static function prune(): string
    {
        return self::guard().<<<'BASH'

            guard_layout
            expected_selected=$1
            shift
            declare -A retained=()
            for pinned in "$@"; do
                guard_release "$pinned"
                retained["$pinned"]=1
            done
            selected=$(selected_name)
            test "$selected" = "$expected_selected"
            previous=
            if [ -e "$state/previous-$selected" ]; then
                test -f "$state/previous-$selected" && test ! -L "$state/previous-$selected"
                previous=$(cat -- "$state/previous-$selected")
                guard_release "$previous"
            fi
            while IFS= read -r -d '' entry; do
                candidate=${entry##*/}
                if [ "$candidate" = "$selected" ] || [ "$candidate" = "$previous" ]; then continue; fi
                # Fail closed on an unregistered path; never delete by age or prefix alone.
                inspect_release "$candidate"
                if [ "$broken_release" = 1 ]; then continue; fi
                if [ "${retained[$candidate]:-}" = 1 ]; then continue; fi
                git -C "$home" worktree remove --force -- "$release"
                rm -f -- "$state/previous-$candidate" "$state/restored-$candidate" "$state/release-$candidate"
                snapshot="$state/snapshot-$candidate"
                if [ -e "$snapshot" ]; then
                    test -d "$snapshot" && test ! -L "$snapshot"
                    rm -rf -- "$snapshot"
                fi
            done < <(find -P "$releases" -mindepth 1 -maxdepth 1 -print0)
            BASH;
    }
}
