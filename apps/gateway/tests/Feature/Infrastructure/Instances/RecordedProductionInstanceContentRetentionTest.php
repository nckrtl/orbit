<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionReleaseLayout;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Instances\RecordedProductionInstanceContentRetention;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->sandbox = sys_get_temp_dir().'/orbit-production-retention-'.Str::uuid();
    $this->checkout = $this->sandbox.'/site';
    $this->marker = $this->checkout.'/public/index.php';
    $this->files->makeDirectory(dirname($this->marker), 0o750, true);
    file_put_contents($this->marker, "<?php echo 'production-retained';\n");
    chmod($this->marker, 0o640);
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->sandbox);
});

it('records retained production identity without changing content bytes or ownership', function (): void {
    $project = Project::query()->create([
        'name' => 'Retained',
        'slug' => 'retained',
        'repository_url' => 'https://example.test/retained.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $node = Node::query()->create([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.81',
        'wireguard_ip' => '10.44.0.81',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => LifecycleStatus::Active]);
    $instance = Instance::query()
        ->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'production',
            'environment' => 'production',
            'checkout_path' => $this->checkout,
            'branch' => null,
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::SourceResolved,
        ])
        ->load('project');
    $bytesBefore = file_get_contents($this->marker);
    $identityBefore = stat($this->marker);
    $layout = new class implements ProductionReleaseLayout
    {
        /** @var list<int> */
        public array $cleared = [];

        public function validateCurrent(Instance $instance): void {}

        public function clearCurrent(Instance $instance): void
        {
            $this->cleared[] = $instance->id;
        }
    };
    $retention = new RecordedProductionInstanceContentRetention($layout);
    $inventory = $retention->inventory($instance);
    $member = new InstanceRemovalMember([
        'instance_id' => $instance->id,
        'project_id' => $instance->project_id,
        'node_id' => $instance->node_id,
        'name' => $instance->name,
        'environment' => $instance->defaultAppEnv(),
        'source_layout' => $inventory->layout,
        'repository_identity' => $inventory->repositoryIdentity,
        'checkout_path' => $inventory->checkoutPath,
        'root' => $inventory->root,
        'branch' => $inventory->branch,
        'starting_commit' => $inventory->startingCommit,
        'common_repository_path' => $inventory->commonRepositoryPath,
        'source_identity' => $inventory->sourceIdentity,
        'linked_worktree_paths' => $inventory->linkedWorktreePaths,
        'source_digest' => $inventory->digest,
    ]);

    $retention->prepare($member);
    $retention->revalidate($member);
    $receipt = $retention->finalize($member);
    clearstatcache(true, $this->marker);
    $identityAfter = stat($this->marker);

    expect($inventory->linkedWorktreePaths)
        ->toBe([])
        ->and($inventory->branch)
        ->toBeNull()
        ->and($receipt)
        ->toBe(hash('sha256', "production-retained\0{$inventory->digest}"))
        ->and($layout->cleared)
        ->toBe([$instance->id])
        ->and(file_get_contents($this->marker))
        ->toBe($bytesBefore)
        ->and($identityAfter['uid'])
        ->toBe($identityBefore['uid'])
        ->and($identityAfter['gid'])
        ->toBe($identityBefore['gid'])
        ->and($identityAfter['mode'])
        ->toBe($identityBefore['mode']);
});
