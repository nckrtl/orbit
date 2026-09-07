<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\MountPath;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/**
 * Prepare the cloned guests of one discovery attempt for a mounted worktree.
 *
 * Two ordered phases run after the clones boot: `mount.source` proves the
 * worktree is mounted on every checkout role, places the preserved gateway
 * environment, and links the CLI onto the guest `PATH`; `repair.identity`
 * points the nodes at the cloned gateway and restarts PHP-FPM so no cache names
 * the hidden snapshot checkout. The mount proof alone is what verify and sync
 * re-run before touching a mounted topology.
 */
final readonly class DiscoveryGuestPreparer
{
    public function __construct(
        private IncusHost $host,
    ) {}

    /** Prove the worktree is mounted on every checkout role; nothing is written. */
    public function assertSourceMounted(TopologyTarget $target): void
    {
        $commands = [];
        foreach ($target->recipe->checkoutNodeKeys() as $role) {
            $commands["mountpoint.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand(['mountpoint', '-q', '--', MountPath::GUEST_SOURCE], 30),
            ];
        }
        $this->assertGuestBatch($this->host->execAll($commands), 'The worktree is not mounted on');
    }

    /**
     * The mount hides the snapshot checkout, so the gateway `.env` the topology snapshot
     * build preserved is placed into the mounted worktree when it is absent there.
     * It lands in the host worktree (gitignored) and is never overwritten.
     */
    public function placeGatewayEnvironment(TopologyTarget $target): void
    {
        $environment = $this->host->exec($target->instance('gateway'), new GuestCommand([
            'sh',
            '-c',
            '[ -e "$1" ] || install -o 1000 -g 1000 -m 0600 -- "$2" "$1"',
            'orbit-e2e',
            MountPath::GUEST_SOURCE.'/apps/gateway/.env',
            WorktreeSynchronizer::GATEWAY_ENV_COPY,
        ], 30));
        if (! $environment->successful()) {
            throw new RuntimeException(
                'The gateway environment could not be placed into the mounted worktree; '
                .'the promoted topology snapshot generation must be refreshed so it preserves '
                .WorktreeSynchronizer::GATEWAY_ENV_COPY
                .'.',
            );
        }
    }

    /** Expose `orbit` by name for the orbit user on every checkout role. */
    public function exposeOrbitCli(TopologyTarget $target): void
    {
        $commands = [];
        foreach ($target->recipe->checkoutNodeKeys() as $role) {
            $commands["orbit-cli.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => GuestCommand::linkOrbitCli(),
            ];
        }
        $this->assertGuestBatch($this->host->execAll($commands), 'The orbit CLI could not be linked onto the PATH on');
    }

    /**
     * A clone keeps its snapshot's WireGuard endpoint and PHP caches: point the
     * nodes at the cloned gateway and drop opcache/realpath state that names the
     * hidden snapshot checkout.
     */
    public function repairCloneIdentity(TopologyTarget $target): void
    {
        $instances = array_combine(
            TopologyProfile::ROLES,
            array_map($target->instance(...), TopologyProfile::ROLES),
        );
        $addresses = $this->host->globalIpv4All($instances);
        $retarget = [];
        $gateway = $target->recipe->nodeForRole('gateway')->key;
        foreach (TopologyProfile::ROLES as $node) {
            if ($node === $gateway) {
                continue;
            }
            $retarget["retarget-vpn.{$node}"] = [
                'instance' => $instances[$node],
                'command' => new GuestCommand(['/usr/local/bin/retarget-vpn.sh', $addresses[$gateway]], 300),
            ];
        }
        $this->assertGuestBatch($this->host->execAll($retarget), 'WireGuard retargeting failed on');

        $restart = [];
        foreach ($target->recipe->checkoutNodeKeys() as $role) {
            $restart["php-fpm.{$role}"] = [
                'instance' => $instances[$role],
                'command' => new GuestCommand(['systemctl', 'restart', 'php8.5-fpm'], 120),
            ];
        }
        $this->assertGuestBatch($this->host->execAll($restart), 'PHP-FPM restart failed on');
    }

    /** @param array<string, GuestCommandResult> $results */
    private function assertGuestBatch(array $results, string $message): void
    {
        $failed = [];
        foreach ($results as $label => $result) {
            if (! $result->successful()) {
                $failed[] = $label;
            }
        }
        if ($failed !== []) {
            throw new RuntimeException($message.' '.implode(', ', $failed).'.');
        }
    }
}
