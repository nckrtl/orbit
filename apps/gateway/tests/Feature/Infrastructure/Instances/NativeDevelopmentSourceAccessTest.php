<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Infrastructure\Instances\NativeDevelopmentSourceAccess;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Route;
use Tests\Support\IncusRuntimeWorkspace;
use Tests\Support\UpCloudRuntimeWorkspace;

use function Pest\Laravel\mock;

it('grants preview source access only through the verified enrolled Project guest', function (string $provider): void {
    $workspace = $provider === 'incus' ? IncusRuntimeWorkspace::create() : UpCloudRuntimeWorkspace::create();
    $workspace->project->update(['type' => 'laravel-app', 'root' => 'public']);
    $workspace->update(['task_workspace_routed' => true, 'root' => 'public', 'selected_php_version' => '8.5', 'status' => InstanceState::SourceResolved]);
    $route = Route::query()->create(['project_id' => $workspace->project_id, 'cluster_id' => $workspace->node->cluster_id,
        'generation_basis_node_id' => $workspace->node_id, 'domain' => $workspace->name.'.dlf.test',
        'provenance' => 'generated', 'publication' => 'private', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $workspace->id, 'position' => 0]);
    $route->publishSites();
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
    mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
    mock(SshExecutor::class)->shouldReceive('execute')->once()
        ->withArgs(function ($connection, RemoteCommand $command) use ($workspace): bool {
            expect($connection->host)->toBe($workspace->node->wireguard_ip);
            expect($command->arguments)->toContain('/home/orbit/orbit', 'public');

            return true;
        })->andReturn(new CommandResult(0, '', '', 1, false));

    app(NativeDevelopmentSourceAccess::class)->grant($workspace);
})->with(['incus', 'upcloud']);
