<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Fleet\FleetNodeVisitor;
use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Fleet\ReleaseHistory;
use App\Domain\Nodes\RoleName;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Config;

/** Nodes, roles, and fakes for the fleet rollout tests. */
final class FleetFixtures
{
    public const string Commit = '0123456789abcdef0123456789abcdef01234567';

    private static int $address = 10;

    /** Points the Gateway checkout at a temporary in-place checkout: no release layout, no release records. */
    public static function inPlace(): string
    {
        $base = sys_get_temp_dir().'/orbit-fleet-layout-'.bin2hex(random_bytes(4));
        mkdir($base.'/orbit/apps/gateway', 0755, true);
        Config::set('orbit.gateway_checkout', $base.'/orbit/apps/gateway');
        app()->instance(FleetServingRelease::class, new FleetServingRelease($base.'/orbit/apps/gateway'));

        return $base;
    }

    /** Turns the temporary checkout into the release layout serving the commit's release. */
    public static function adopt(string $commit): void
    {
        $base = dirname(Config::string('orbit.gateway_checkout'), 3);
        $id = substr($commit, 0, 12);
        @mkdir($base.'/releases/'.$id.'/apps/gateway', 0755, true);
        file_put_contents($base.'/releases/'.$id.'/REVISION', $commit."\n");

        if (is_link($base.'/orbit')) {
            unlink($base.'/orbit');
        } elseif (is_dir($base.'/orbit')) {
            exec('rm -rf '.escapeshellarg($base.'/orbit'));
        }

        symlink('releases/'.$id, $base.'/orbit');
    }

    /** Runs this process as if from the release of the commit, as the unit does after a release. */
    public static function runFrom(string $commit): void
    {
        $base = dirname(Config::string('orbit.gateway_checkout'), 3);
        app()->instance(FleetServingRelease::class, new FleetServingRelease($base.'/releases/'.substr($commit, 0, 12).'/apps/gateway'));
    }

    /** @param list<RoleName> $roles */
    public static function node(string $name, array $roles = [], string $platform = 'linux', bool $managed = true, LifecycleStatus $status = LifecycleStatus::Active): Node
    {
        $address = ++self::$address;
        $node = Node::query()->create([
            'name' => $name,
            'status' => $status,
            'platform' => $platform,
            'architecture' => $platform === 'macos' ? 'arm64' : 'x86_64',
            'public_ssh_host' => '203.0.113.'.$address,
            'public_ssh_port' => 22,
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.'.$address,
            'ssh_host_key_type' => $managed ? 'ssh-ed25519' : null,
            'ssh_host_key' => $managed ? 'ssh-ed25519 AAAA'.$name : null,
            'ssh_host_fingerprint' => $managed ? 'SHA256:'.$name : null,
        ]);

        foreach ($roles as $role) {
            NodeRole::query()->create(['node_id' => $node->id, 'role' => $role, 'status' => LifecycleStatus::Active]);
        }

        return $node->load('roles');
    }

    /**
     * Binds a published CLI release, a fake Node visitor, the alert recorder, and units, with the rollout on.
     *
     * @param  array<int, NodeFootprintArtifact>  $artifacts
     * @return array{visitor: FakeFleetNodeVisitor, catalog: FakeCliReleaseCatalog, alerts: RecordingReleaseAlertNotifier, units: FakeFleetConvergeUnits}
     */
    public static function bind(array $artifacts = [], bool $published = true): array
    {
        Config::set('app.version', self::Commit);
        Config::set('fleet.rollout', true);
        self::inPlace();
        $visitor = new FakeFleetNodeVisitor;
        $catalog = new FakeCliReleaseCatalog($published);
        $alerts = new RecordingReleaseAlertNotifier;
        $units = new FakeFleetConvergeUnits;
        app()->instance(ReleaseHistory::class, new FakeReleaseHistory);
        app()->instance(CliReleaseCatalog::class, $catalog);
        app()->instance(FleetNodeVisitor::class, $visitor);
        app()->instance(ReleaseAlertNotifier::class, $alerts);
        app()->instance(FleetConvergeUnits::class, $units);
        app()->instance(NodeFootprint::class, new NodeFootprint($artifacts));
        // Never reach a real Node, and keep agent versions out of the shared file store.
        app()->instance(NodeUpdateLock::class, new NodeUpdateLock(FleetTestSsh::shell(new ScriptedSshExecutor)));
        app()->instance(AgentReportedVersions::class, new AgentReportedVersions(new Repository(new ArrayStore)));

        return ['visitor' => $visitor, 'catalog' => $catalog, 'alerts' => $alerts, 'units' => $units];
    }
}
