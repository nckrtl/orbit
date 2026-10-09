<?php

declare(strict_types=1);

use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProjectSandboxRuntimeGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use Tests\Support\IncusRuntimeWorkspace;
use Tests\Support\UpCloudRuntimeWorkspace;

use function Pest\Laravel\mock;

it('prepares a private preview through native provisioning only on the enrolled Project guest', function (string $provider): void {
    $workspace = $provider === 'incus' ? IncusRuntimeWorkspace::create() : UpCloudRuntimeWorkspace::create();
    $workspace->project->update(['type' => 'laravel-app', 'apps' => fixture_apps('web', 'laravel-app')]);
    $workspace->update(['task_workspace_routed' => true, 'app_overrides' => fixture_app_overrides('web'), 'status' => InstanceState::SourceResolved]);
    mock(VitePortRuntime::class)->shouldReceive('selectPort')->twice()
        ->withArgs(fn (Node $node, int $preferred, array $excluded): bool => $node->id === $workspace->node_id && in_array($preferred, [5173, 13714], true))
        ->andReturnUsing(fn (Node $node, int $preferred, array $excluded): int => $preferred);
    $configuration = mock(DevelopmentInstanceConfigurator::class);
    $configuration->shouldReceive('inspect')->once()->andReturn(new DevelopmentSourceProfile('8.5', true));
    $configuration->shouldReceive('configureLaravelUrl')->once()
        ->withArgs(fn (Instance $instance, string $url, ?string $app = null): bool => $instance->node_id === $workspace->node_id && $url === 'https://web.'.$workspace->name.'.dlf.test');
    mock(DevelopmentRouteProjector::class)->shouldReceive('converge')->once()->andReturnUsing(function ($instance, $route) use ($workspace): void {
        ProjectSandboxRuntimeGuard::assertRuntime($instance, $route);
        expect($instance->node_id)->toBe($workspace->node_id);
        expect($instance->applicationDirectory())->toBe('/home/orbit/orbit/web');
    });
    $development = app(DevelopmentInstanceProvisioner::class);
    $development->reserve($workspace, null);
    $result = $development->complete($workspace, null);
    $development->reserve($result, null);
    $development->complete($result, null);
    expect($result->status)->toBe(InstanceState::Active);
    expect($result->vite_port)->toBe(5173);
    expect($result->ssr_port)->toBe(13714);
    $this->assertDatabaseHas('ssr_port_assignments', ['instance_id' => $result->id, 'node_id' => $workspace->node_id, 'port' => 13714]);
    expect($result->routes()->count())->toBe(1);
    expect($result->routes()->first()->domain)->toBe('web.'.$workspace->name.'.dlf.test');
    expect($result->routes()->first()->publication->value)->toBe('private');
    expect(fn () => InstanceSandboxGuard::assertHostOperation($result))->toThrow(ResourceOperationException::class);
})->with(['incus', 'upcloud']);

it('refuses a changed Project runtime owner before native setup touches a Node', function (string $fault): void {
    $workspace = IncusRuntimeWorkspace::create();
    $sandbox = $workspace->taskSandbox;
    match ($fault) {
        'host Node' => $workspace->update(['node_id' => $sandbox->spec['host_id']]),
        'branch' => $workspace->update(['branch_override' => 'main']),
        'path' => $workspace->update(['checkout_path' => '/fast/apps/foreign']),
        'parked' => $sandbox->update(['state' => 'stopped']),
        'unconfirmed policy' => $sandbox->forceFill(['enrollment' => array_diff_key($sandbox->enrollment, ['hub_confirmed_at' => true])])->save(),
        'extra group' => Task::topLevel()->create(['project_id' => $workspace->project_id, 'title' => 'Foreign', 'brief' => 'Foreign', 'status' => 'todo', 'taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]),
    };
    mock(DevelopmentInstanceConfigurator::class)->shouldNotReceive('inspect', 'configureLaravelUrl');
    mock(DevelopmentRouteProjector::class)->shouldNotReceive('converge');
    expect(fn () => app(DevelopmentInstanceProvisioner::class)->reserve($workspace->fresh(), null))->toThrow(ResourceOperationException::class);
    expect($workspace->routes()->exists())->toBeFalse();
})->with(['host Node', 'branch', 'path', 'parked', 'unconfirmed policy', 'extra group']);
