<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\ProjectLifecycleStep;

beforeEach(function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->project = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->url = "/api/v1/projects/{$this->project->id}/setup-steps";
});

it('records ordered setup steps and keeps the command out of activity', function (): void {
    $this->postJson($this->url, [
        'name' => 'install-php',
        'command' => 'composer install --no-interaction',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'install-php')
        ->assertJsonPath('data.timeout_seconds', 240);

    $this->postJson($this->url, [
        'name' => 'install-js',
        'command' => 'npm ci',
        'before' => 'install-php',
        'timeout_seconds' => 120,
    ])->assertCreated();

    $this->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.0.name', 'install-js')
        ->assertJsonPath('data.1.name', 'install-php');

    $activity = Activity::query()->where('command', 'instance:setup-step:create')->latest('id')->first();
    expect(json_encode($activity?->properties))->not->toContain('composer install');

    $this->patchJson($this->url.'/install-js', ['command' => 'npm install'])
        ->assertOk()
        ->assertJsonPath('data.command', 'npm install');

    $this->deleteJson($this->url.'/install-php')->assertOk();

    expect(ProjectLifecycleStep::query()->where('app_id', $this->project->id)->pluck('name')->all())->toBe(['install-js']);
});

it('refuses a duplicate name and a timeout above the cap without storing a change', function (): void {
    $this->postJson($this->url, ['name' => 'install-php', 'command' => 'composer install'])->assertCreated();

    $this->postJson($this->url, ['name' => 'install-php', 'command' => 'composer update'])
        ->assertUnprocessable();

    $this->postJson($this->url, ['name' => 'slow', 'command' => 'composer install', 'timeout_seconds' => 541])
        ->assertUnprocessable();

    // One API request runs the whole list, so the list must fit the request too.
    $this->postJson($this->url, ['name' => 'slow', 'command' => 'composer install', 'timeout_seconds' => 301])
        ->assertUnprocessable();

    expect(ProjectLifecycleStep::query()->where('app_id', $this->project->id)->count())->toBe(1);
});

it('records a teardown step on its own list', function (): void {
    $this->postJson("/api/v1/projects/{$this->project->id}/teardown-steps", [
        'name' => 'drop-sqlite',
        'command' => 'rm -f database/database.sqlite',
    ])->assertCreated()
        ->assertJsonPath('data.name', 'drop-sqlite');

    $this->getJson($this->url)->assertOk()->assertJsonCount(0, 'data');
});

it('lowers stored step timeouts that one request could never honor', function (): void {
    foreach ([['slow', 900, 0], ['default', 600, 1], ['fits', 120, 2]] as [$name, $timeout, $position]) {
        ProjectLifecycleStep::query()->create([
            'app_id' => $this->project->id,
            'phase' => 'setup',
            'name' => $name,
            'command' => 'true',
            'timeout_seconds' => $timeout,
            'position' => $position,
        ]);
    }

    (require database_path('migrations/2026_09_26_090000_cap_project_lifecycle_step_timeouts.php'))->up();

    expect(ProjectLifecycleStep::query()->orderBy('position')->pluck('timeout_seconds')->all())->toBe([540, 540, 120]);

    $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.timeout_seconds', 540);
});

it('keeps a list over the total limit editable after the migration, as long as an edit does not raise its total', function (): void {
    foreach (range(0, 6) as $position) {
        ProjectLifecycleStep::query()->create([
            'app_id' => $this->project->id,
            'phase' => 'setup',
            'name' => "step-{$position}",
            'command' => 'true',
            'timeout_seconds' => 900,
            'position' => $position,
        ]);
    }

    (require database_path('migrations/2026_09_26_090000_cap_project_lifecycle_step_timeouts.php'))->up();

    // 7 x 540 = 3,780 seconds, over the 540-second list limit.
    expect(ProjectLifecycleStep::query()->sum('timeout_seconds'))->toBe(3_780);

    // Raising the total stays refused.
    $this->postJson($this->url, ['name' => 'extra', 'command' => 'true', 'timeout_seconds' => 1])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.body.0', 'The lifecycle list timeout total is too large.');
    $this->patchJson($this->url.'/step-5', ['command' => 'false'])->assertOk();

    // Lowering, reordering, and removing are accepted.
    $this->patchJson($this->url.'/step-0', ['timeout_seconds' => 60])->assertOk()->assertJsonPath('data.timeout_seconds', 60);
    $this->patchJson($this->url.'/step-0', ['after' => 'step-6'])->assertOk();
    $this->deleteJson($this->url.'/step-1')->assertOk();

    expect(ProjectLifecycleStep::query()->sum('timeout_seconds'))->toBe(2_760);

    foreach (['step-2', 'step-3', 'step-4', 'step-5'] as $name) {
        $this->deleteJson($this->url.'/'.$name)->assertOk();
    }

    // Below the old total, a list still over the limit keeps shrinking; once it fits, the limit applies again.
    expect(ProjectLifecycleStep::query()->sum('timeout_seconds'))->toBe(600)
        ->and(ProjectLifecycleStep::query()->orderBy('position')->pluck('name')->all())->toBe(['step-6', 'step-0']);
    $this->patchJson($this->url.'/step-6', ['timeout_seconds' => 400])->assertOk();
    $this->postJson($this->url, ['name' => 'extra', 'command' => 'true', 'timeout_seconds' => 80])->assertCreated();
    $this->postJson($this->url, ['name' => 'too-much', 'command' => 'true', 'timeout_seconds' => 1])->assertUnprocessable();
});

it('refuses a stored step above the limit with a clear error until the migration runs', function (): void {
    ProjectLifecycleStep::query()->create([
        'app_id' => $this->project->id,
        'phase' => 'setup',
        'name' => 'unmigrated',
        'command' => 'true',
        'timeout_seconds' => 600,
        'position' => 0,
    ]);

    $this->getJson($this->url)
        ->assertConflict()
        ->assertJsonPath('error.code', 'lifecycle_step.migration_pending')
        ->assertJsonPath('error.message', 'Lifecycle step [unmigrated] stores a 600-second timeout, above the 540-second limit. Run the Gateway database migrations.');
});
