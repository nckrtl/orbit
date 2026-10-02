<?php

declare(strict_types=1);

namespace App\Infrastructure\SourceControl;

/** Suppress checkout-controlled hooks and fsmonitor for Gateway shell reads and mutations. */
final readonly class WorkspaceGit
{
    /** Content-reading Git can start filters. Keep it separate from credential-bearing git_read. */
    public static function workerPreamble(?string $worker): string
    {
        $invoke = $worker === null ? 'command git' : 'sudo -n -u '.escapeshellarg($worker).' -H -- git';
        $trust = $worker === null ? '' : ' -c safe.directory="$2"';
        $guard = $worker === null ? '' : 'worker_uid=$(id -u '.escapeshellarg($worker).') || exit 126'."\n".
            'test "$worker_uid" != 0 && test "$worker_uid" != "$(id -u)" || exit 126'."\n";

        return "workspace_git() (\n".$guard.<<<'BASH'
            unset GIT_CONFIG_COUNT GIT_CONFIG_PARAMETERS GIT_ASKPASS SSH_ASKPASS GH_TOKEN GITHUB_TOKEN
            for variable in ${!GIT_CONFIG_KEY_@} ${!GIT_CONFIG_VALUE_@}; do unset "$variable"; done

            BASH.'if [ "${1:-}" = -C ]; then'."\n".
            $invoke.' -c core.hooksPath=/dev/null -c core.fsmonitor=false'.$trust.' "$@"'."\nelse\n".
            $invoke.' -c core.hooksPath=/dev/null -c core.fsmonitor=false "$@"'."\nfi\n)\n";
    }

    public static function bashPreamble(?string $checkout = null): string
    {
        $trust = $checkout === null ? '' : ' -c '.escapeshellarg('safe.directory='.$checkout);

        return 'git() { command git -c core.hooksPath=/dev/null -c core.fsmonitor=false'.$trust.' "$@"; }'."\n";
    }
}
