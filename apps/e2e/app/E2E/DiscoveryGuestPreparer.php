<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\MountPath;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use JsonException;
use RuntimeException;
use Throwable;

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

    /** Apply the mounted Gateway's pending migrations without provisioning any product state. */
    public function prepareGatewaySchema(TopologyTarget $target): void
    {
        try {
            $result = $this->host->exec(
                $target->instance('gateway'),
                GuestCommand::asOrbitUser([
                    'env',
                    '-C',
                    MountPath::GUEST_SOURCE.'/apps/gateway',
                    'ORBIT_GATEWAY_CHECKOUT='.MountPath::GUEST_SOURCE.'/apps/gateway',
                    'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
                    'php',
                    'artisan',
                    'migrate',
                    '--force',
                    '--no-interaction',
                ], 900),
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Gateway schema preparation could not complete: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! $result->successful()) {
            throw new RuntimeException(
                "Gateway schema preparation failed with exit code {$result->exitCode}.",
            );
        }
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
        $gateway = $target->recipe->nodeForRole('gateway')->key;
        $endpoints = $this->retargetGateway($target, $addresses);
        $script = $this->guestResource('retarget-vpn.sh');
        $retarget = [];
        foreach (TopologyProfile::ROLES as $node) {
            if ($node === $gateway) {
                continue;
            }
            $retarget["retarget-vpn.{$node}"] = [
                'instance' => $instances[$node],
                'command' => new GuestCommand(['bash', '-s', '--', $addresses[$gateway], $endpoints[$node]], 300, $script),
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

    /**
     * @param  array<string, string>  $addresses
     * @return array<string, string>
     */
    private function retargetGateway(TopologyTarget $target, array $addresses): array
    {
        if (count(array_unique($addresses)) !== count(TopologyProfile::ROLES)) {
            throw new RuntimeException('Cloned Nodes must have distinct global IPv4 addresses.');
        }
        $results = $this->host->execAll([
            'retarget-gateway' => [
                'instance' => $target->instance('gateway'),
                'command' => GuestCommand::asOrbitUser([
                    'php', MountPath::GUEST_SOURCE.'/apps/e2e/resources/guest/retarget-gateway.php', '/home/orbit/.orbit/gateway.sqlite',
                    ...array_map(static fn (string $role): string => $addresses[$role], TopologyProfile::ROLES),
                ], 60),
            ],
        ]);
        $this->assertGuestBatch($results, 'Gateway clone identity preparation failed on');
        try {
            $endpoints = json_decode($results['retarget-gateway']->stdout, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Gateway clone identity preparation returned invalid endpoints.');
        }
        if (! is_array($endpoints) || array_keys($endpoints) !== ['app-dev', 'app-prod']) {
            throw new RuntimeException('Gateway clone identity preparation returned incomplete endpoints.');
        }
        foreach ($endpoints as $endpoint) {
            if (! is_string($endpoint)
                || preg_match('/\A'.preg_quote($addresses['gateway'], '/').':([1-9][0-9]{0,4})\z/D', $endpoint, $parts) !== 1
                || (int) $parts[1] > 65_535) {
                throw new RuntimeException('Gateway clone identity preparation returned invalid endpoints.');
            }
        }

        return $endpoints;
    }

    private function guestResource(string $name): string
    {
        $source = file_get_contents(__DIR__.'/../../resources/guest/'.$name);
        if (! is_string($source) || $source === '') {
            throw new RuntimeException('The clone identity preparation resource is missing.');
        }

        return $source;
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
