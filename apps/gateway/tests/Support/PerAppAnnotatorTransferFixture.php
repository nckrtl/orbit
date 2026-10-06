<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppDev\AnnotatorEndpoint;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\Process as ProcessModel;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final readonly class PerAppAnnotatorTransferFixture
{
    public string $sandbox;

    public LocalInstanceTransferTransport $transport;

    public Instance $instance;

    public Node $source;

    public Node $destination;

    public function __construct(?Instance $instance = null, ?Node $destination = null)
    {
        $this->sandbox = sys_get_temp_dir().'/orbit-1166-recovery-'.bin2hex(random_bytes(8));
        $this->transport = new LocalInstanceTransferTransport($this->sandbox);
        $node = static fn (string $name): Node => Node::query()->create([
            'name' => $name, 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
            'public_ssh_host' => '127.0.0.1', 'wireguard_ip' => '127.0.0.'.(Node::query()->count() + 1),
        ]);
        $this->source = $instance?->node ?? $node('recovery-source');
        $this->destination = $destination ?? $node('recovery-destination');
        $project = $instance?->project ?? Project::query()->create([
            'name' => 'Recovery', 'slug' => 'recovery', 'repository_url' => 'https://example.test/recovery.git',
        ]);
        $project->update(['apps' => array_map(static fn (string $app): array => [
            'name' => $app, 'type' => 'laravel-app', 'path' => 'apps/'.$app, 'web_root' => 'public',
        ], ['docs', 'web'])]);
        $this->instance = $instance ?? Instance::query()->create([
            'project_id' => $project->id, 'node_id' => $this->source->id, 'name' => 'main',
            'environment' => 'development', 'source_layout' => 'checkout', 'checkout_path' => $this->sandbox.'/source',
        ]);
        $this->instance->update(['checkout_path' => $this->sandbox.'/source', 'source_is_laravel' => true]);
        $this->instance->refresh();
        foreach (['docs', 'web'] as $app) {
            $store = $this->transport->storePath($this->source->wireguard_ip, 'instance-'.$this->instance->id.'-'.$app);
            new Filesystem()->ensureDirectoryExists($store, 0700);
            file_put_contents($store.'/annotations.json', 'durable '.$app);
            $path = $this->sandbox.'/source/apps/'.$app;
            new Filesystem()->ensureDirectoryExists($path, 0700);
            file_put_contents($path.'/.env', 'APP_KEY='.$app);
            chmod($path.'/.env', 0600);
            ProcessModel::query()->create([
                'owner_type' => Instance::MorphAlias, 'owner_id' => $this->instance->id, 'app' => $app,
                'name' => $app.'-annotator', 'runtime' => 'systemd', 'working_directory' => $path,
                'runtime_config' => ['preset' => 'annotator'], 'desired_state' => 'running',
                'status' => 'active', 'restart_policy' => 'always',
            ]);
        }
        new Process(['git', 'init', '--quiet', '--initial-branch=main'], $this->sandbox.'/source')->mustRun();
        new Process(['git', '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '--allow-empty', '-m', 'Start'], $this->sandbox.'/source')->mustRun();
    }

    public function transfer(): InstanceTransfer
    {
        $journal = [];
        foreach (['docs', 'web'] as $app) {
            $journal[$app] = [
                'source_route_id' => null, 'destination_route_id' => null, 'destination_domain' => null,
                'source_router_node_id' => null, 'imported_environment_keys' => [],
                'annotator' => ['source_store' => AnnotatorEndpoint::forInstance($this->instance, $app)],
            ];
        }

        return InstanceTransfer::query()->create([
            'instance_id' => $this->instance->id, 'source_node_id' => $this->source->id,
            'destination_node_id' => $this->destination->id, 'destination_name' => $this->instance->name,
            'destination_path' => $this->sandbox.'/destination', 'source_path' => $this->sandbox.'/source',
            'source_layout' => 'checkout', 'status' => 'in_progress', 'current_step' => 'reserved', 'app_journal' => $journal,
        ]);
    }

    public function dispose(): void
    {
        foreach (InstanceTransfer::query()->where('instance_id', $this->instance->id)->get() as $transfer) {
            foreach ($transfer->app_journal ?? [] as $entry) {
                $archive = $entry['annotator']['archive'] ?? null;
                if (is_string($archive) && str_starts_with($archive, '/tmp/orbit-transfer-'.$transfer->id.'-')) {
                    foreach ([$archive, substr($archive, 0, -4).'-restore.tar'] as $path) {
                        if (is_file($path) && ! is_link($path) && fileowner($path) === posix_geteuid()) {
                            unlink($path);
                        }
                    }
                }
            }
            new Filesystem()->deleteDirectory(storage_path('app/transfer-staging/'.$transfer->id));
        }
        new Filesystem()->deleteDirectory($this->sandbox);
    }
}
