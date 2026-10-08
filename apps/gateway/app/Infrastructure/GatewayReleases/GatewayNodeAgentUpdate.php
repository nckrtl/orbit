<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use InvalidArgumentException;
use Throwable;

/**
 * Brings the Gateway Node's own `orbit-agent` to the release's pin. The fleet rollout leaves the Gateway's machine
 * out, so the release's runtime handoff updates it here, through local `sudo` and never over SSH to itself.
 *
 * One script runs under `/run/lock/orbit-self-update.lock`, the lock `orbit self-update` and the agent converge hold,
 * so it never swaps the agent while one of them does. It takes the converge's steps: a matching binary is left alone;
 * otherwise a candidate is downloaded, checked against the pinned SHA-256, given `root:root` and `0755`, and renamed
 * into place. Only then does it restart the agent, and it checks that the agent stays active for the health window
 * of `orbit self-update`. A restart that fails or does not stay up restores the previous binary.
 *
 * It never throws: the result is part of the release record, and a failure there never fails the release.
 */
final readonly class GatewayNodeAgentUpdate
{
    /** How long the update waits for a self-update or an agent converge that holds the lock. */
    public const int LockWaitSeconds = 120;

    /** How long the restarted agent must stay active without a restart, as `orbit self-update` checks it. */
    public const int HealthSeconds = 5;

    /**
     * The longest the script may run: the lock wait, the 120-second download, a restart and a restore of 60 seconds
     * each, and the health window with its 10-second `systemctl show` calls. Every step has its own limit, so the
     * process limit never cuts the script off between the swap and the restart.
     */
    public const int TimeoutSeconds = self::LockWaitSeconds + 360;

    private const int BusyExit = 75;

    /** Arguments: binary, unit, unit marker, download URL, pinned SHA-256, service, health seconds. */
    public const string Script = <<<'BASH'
        binary=$1
        unit=$2
        marker=$3
        url=$4
        expected=$5
        service=$6
        health=$7
        candidate="$binary.orbit-candidate"
        previous="$binary.orbit-previous"

        [ -f "$unit" ] && [ "$(head -n 1 -- "$unit")" = "$marker" ] || exit 10

        installed=$(sha256sum -- "$binary" 2>/dev/null | cut -d ' ' -f 1)
        if [ "$installed" = "$expected" ]; then
          printf 'unchanged\n'
          exit 0
        fi

        rm -f -- "$candidate"
        if ! curl --fail --location --silent --show-error --connect-timeout 20 --max-time 120 --output "$candidate" -- "$url"; then
          rm -f -- "$candidate"
          exit 20
        fi
        if [ "$(sha256sum -- "$candidate" | cut -d ' ' -f 1)" != "$expected" ]; then
          rm -f -- "$candidate"
          exit 21
        fi
        if ! chown root:root -- "$candidate" || ! chmod 0755 -- "$candidate"; then
          rm -f -- "$candidate"
          exit 22
        fi

        kept=0
        if [ -f "$binary" ]; then
          rm -f -- "$previous"
          if ln -- "$binary" "$previous" 2>/dev/null || cp -p -- "$binary" "$previous"; then
            kept=1
          fi
        fi
        if ! mv -fT -- "$candidate" "$binary"; then
          rm -f -- "$candidate"
          exit 22
        fi
        printf 'updated %s\n' "${installed:-none}"

        restarts() {
          local state
          state=$(timeout 10 systemctl show "$service" --property=ActiveState --property=NRestarts) || return 1
          case "$state" in *ActiveState=active*) ;; *) return 1 ;; esac
          printf '%s\n' "$state" | sed -n 's/^NRestarts=//p'
        }

        stays_up() {
          local baseline now
          baseline=$(restarts) && [ -n "$baseline" ] || return 1
          for _ in $(seq "$health"); do
            sleep 1
            now=$(restarts) && [ "$now" = "$baseline" ] || return 1
          done
        }

        restore() {
          if [ "$kept" = 1 ] && mv -fT -- "$previous" "$binary"; then
            if timeout 60 systemctl restart "$service"; then
              printf 'restored\n'
            else
              printf 'restored_stopped\n'
            fi
          fi
        }

        if ! timeout 60 systemctl restart "$service"; then
          restore
          exit 23
        fi
        if ! stays_up; then
          restore
          exit 24
        fi
        BASH;

    public function __construct(
        private ProcessRunner $processes,
        private string $lockPath = NodeAgentFootprint::UpdateLockPath,
    ) {}

    /**
     * @return array<string, mixed> `outcome` is `unchanged`, `updated`, `skipped`, or `failed`
     */
    public function converge(Node $gateway): array
    {
        if ($gateway->platform !== 'linux') {
            return ['outcome' => 'skipped', 'reason' => 'platform'];
        }

        $architecture = is_string($gateway->architecture) ? $gateway->architecture : '';

        try {
            $checksum = NodeAgentFootprint::checksum($architecture);
        } catch (InvalidArgumentException) {
            return $this->failed('agent.architecture_unsupported', 'The Gateway Node has no supported agent architecture.');
        }

        try {
            $result = $this->processes->run(new ProcessInvocation(
                arguments: [
                    'sudo', 'flock', '-w', (string) self::LockWaitSeconds, '-E', (string) self::BusyExit, $this->lockPath,
                    'bash', '-seu', '--',
                    NodeAgentFootprint::BinaryPath, NodeAgentFootprint::UnitPath, NodeAgentFootprint::Marker,
                    NodeAgentFootprint::downloadUrl($architecture), $checksum,
                    NodeAgentFootprint::Service.'.service', (string) self::HealthSeconds,
                ],
                timeout: self::TimeoutSeconds,
                input: self::Script,
            ));
        } catch (Throwable $exception) {
            return $this->failed('agent.install_failed', 'The Gateway Node agent update could not run: '.$exception->getMessage());
        }

        $lines = preg_split('/\R/', trim($result->stdout)) ?: [];
        $updated = preg_grep('/\Aupdated /', $lines);
        $previous = $updated === [] || $updated === false ? null : substr((string) reset($updated), 8);
        $restored = match (true) {
            in_array('restored', $lines, true) => ' The previous orbit-agent is restored and running.',
            in_array('restored_stopped', $lines, true) => ' The previous orbit-agent is restored, but systemctl could not restart it.',
            default => ' No previous orbit-agent was restored.',
        };

        return match (true) {
            $result->succeeded() && $previous !== null => [
                'outcome' => 'updated',
                'version' => NodeAgentFootprint::Version,
                'previous_sha256' => $previous === 'none' ? null : $previous,
            ],
            $result->succeeded() => ['outcome' => 'unchanged', 'version' => NodeAgentFootprint::Version],
            $result->exitCode === 10 => ['outcome' => 'skipped', 'reason' => 'not_installed'],
            $result->exitCode === 20 => $this->failed('agent.binary_download_failed', 'The pinned orbit-agent could not be downloaded.'),
            $result->exitCode === 21 => $this->failed('agent.checksum_mismatch', 'The downloaded orbit-agent failed checksum verification.'),
            $result->exitCode === 23 => $this->failed('agent.restart_failed', 'systemctl could not restart orbit-agent.service.'.$restored),
            $result->exitCode === 24 => $this->failed('agent.unhealthy', 'The new orbit-agent did not stay running.'.$restored),
            $result->exitCode === self::BusyExit => $this->failed('node.update_busy', 'A self-update or agent converge held the update lock for more than '.self::LockWaitSeconds.' seconds.'),
            $result->exitCode === 22 => $this->failed('agent.install_failed', 'The pinned orbit-agent could not be moved into place.'),
            default => $this->failed('agent.install_failed', "The Gateway Node agent update exited with code {$result->exitCode}: ".$this->lastLine($result->stderr)),
        };
    }

    /** The last line of the script's error output, cut to 200 characters, so the record says what stopped it. */
    private function lastLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $last = trim((string) end($lines));

        return $last === '' ? 'no error output' : mb_substr($last, 0, 200);
    }

    /** @return array{outcome: string, version: string, error_code: string, message: string} */
    private function failed(string $errorCode, string $message): array
    {
        return ['outcome' => 'failed', 'version' => NodeAgentFootprint::Version, 'error_code' => $errorCode, 'message' => $message];
    }
}
