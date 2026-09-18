<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

final class ComposerDependencyUpdateProgram
{
    public const int DeadlineSeconds = 600;

    public const float SshTimeoutSeconds = 610.0;

    public const float TerminateGraceSeconds = 2.0;

    public static function render(): string
    {
        return <<<'BASH'
root=$1
deadline=$2
case "$root" in /*) ;; *) exit 1 ;; esac
case "$root" in *[[:cntrl:]]*) exit 1 ;; esac
case "$deadline" in ''|*[!0-9]*) exit 1 ;; esac
test "$deadline" -ge 1
owner=$PPID
child=
watchdog=
finished=0
list_tree() {
    printf '%s\n' "$1"
    for kid in $(pgrep -P "$1" 2>/dev/null || true); do
        case "$kid" in ''|*[!0-9]*) ;; *) list_tree "$kid" ;; esac
    done
}
owner_gone() {
    if [ ! -d "/proc/$owner" ]; then
        return 0
    fi
    state=$(awk '{print $3}' "/proc/$owner/stat" 2>/dev/null || true)
    if [ -z "$state" ] || [ "$state" = Z ]; then
        return 0
    fi
    parent=$(ps -o ppid= -p $$ 2>/dev/null | tr -d ' ')
    if [ "$parent" != "$owner" ]; then
        return 0
    fi
    return 1
}
terminate_tree() {
    target=$1
    if [ -z "$target" ]; then
        return
    fi
    pids=$(list_tree "$target")
    if [ -n "$pids" ]; then
        kill -KILL -- $pids 2>/dev/null || true
    fi
}
terminate_owned() {
    terminate_tree "$child"
    if [ -n "$child" ]; then
        wait "$child" 2>/dev/null || true
        child=
    fi
    if [ -n "$watchdog" ]; then
        terminate_tree "$watchdog"
        wait "$watchdog" 2>/dev/null || true
        watchdog=
    fi
}
cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    if [ "$finished" -eq 0 ]; then
        terminate_owned
    fi
    exit "$status"
}
trap cleanup EXIT HUP INT TERM
start=$(date +%s)
/usr/bin/setsid --wait /usr/bin/composer --working-dir "$root" update --no-interaction --no-ansi --no-progress --no-audit </dev/null >/dev/null 2>&1 &
child=$!
/usr/bin/setsid --wait /usr/bin/bash -eu -c '
trap "" HUP INT TERM PIPE
owner=$1
child=$2
deadline=$3
start=$4
list_tree() {
    printf "%s\n" "$1"
    for kid in $(pgrep -P "$1" 2>/dev/null || true); do
        case "$kid" in ""|*[!0-9]*) ;; *) list_tree "$kid" ;; esac
    done
}
terminate_tree() {
    target=$1
    if [ -z "$target" ]; then
        return
    fi
    pids=$(list_tree "$target")
    if [ -n "$pids" ]; then
        kill -KILL -- $pids 2>/dev/null || true
    fi
}
owner_gone() {
    if [ ! -d "/proc/$owner" ]; then
        return 0
    fi
    state=$(awk "{print \$3}" "/proc/$owner/stat" 2>/dev/null || true)
    if [ -z "$state" ] || [ "$state" = Z ]; then
        return 0
    fi
    return 1
}
while kill -0 "$child" 2>/dev/null; do
    if owner_gone; then
        terminate_tree "$child"
        exit 0
    fi
    now=$(date +%s)
    if [ $((now - start)) -ge "$deadline" ]; then
        terminate_tree "$child"
        exit 0
    fi
    sleep 0.1
done
' composer-update-watchdog "$owner" "$child" "$deadline" "$start" </dev/null >/dev/null 2>&1 &
watchdog=$!
while :; do
    if ! kill -0 "$child" 2>/dev/null; then
        break
    fi
    if owner_gone; then
        terminate_owned
        finished=1
        exit 143
    fi
    now=$(date +%s)
    if [ $((now - start)) -ge "$deadline" ]; then
        terminate_owned
        finished=1
        exit 124
    fi
    IFS= read -r -t 0.1 ignored || {
        status=$?
        if [ "$status" -lt 128 ]; then
            terminate_owned
            finished=1
            exit 143
        fi
    }
done
status=0
wait "$child" || status=$?
if [ -n "$watchdog" ]; then
    wait "$watchdog" 2>/dev/null || true
    watchdog=
fi
finished=1
now=$(date +%s)
if [ $((now - start)) -ge "$deadline" ] && [ "$status" -eq 137 ]; then
    exit 124
fi
exit "$status"
BASH;
    }
}
