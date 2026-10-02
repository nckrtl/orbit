<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;

beforeEach(function (): void {
    $gateway = Node::query()->create([
        'name' => 'gateway', 'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
    $this->project = Project::query()->create([
        'name' => 'Acme', 'slug' => 'acme', 'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main', 'root' => 'public',
    ]);
    $this->url = "/api/v1/projects/{$this->project->id}/dev-deploy-steps";
});

describe('Project development deploy steps', function (): void {
    it('documents duplicate creates as refusals rather than successful retries', function (): void {
        $payload = ['name' => 'install', 'command' => 'true'];
        $this->postJson($this->url, $payload)->assertCreated();
        $this->postJson($this->url, $payload)->assertUnprocessable();
        $spec = json_decode((string) file_get_contents(base_path('../../docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
        $responses = $spec['paths']['/api/v1/projects/{project}/dev-deploy-steps']['post']['responses'];
        expect($responses)->toHaveKeys(['201', '422'])->not->toHaveKey('200');
    });

    it('lists creates updates reorders and deletes with required defaulting to true', function (): void {
        $this->getJson($this->url)->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->url, ['name' => 'install', 'command' => 'composer install'])
            ->assertCreated()->assertJsonPath('data.required', true)->assertJsonPath('data.timeout_seconds', 300);
        $this->postJson($this->url, ['name' => 'cache', 'command' => 'php artisan optimize', 'required' => false, 'before' => 'install'])
            ->assertCreated()->assertJsonPath('data.required', false);
        $this->patchJson($this->url.'/cache', ['timeout_seconds' => 90, 'after' => 'install'])
            ->assertOk()->assertJsonPath('data.required', false)->assertJsonPath('data.command', 'php artisan optimize');
        $this->patchJson($this->url.'/install', ['command' => 'composer install --no-interaction', 'required' => false])
            ->assertOk()->assertJsonPath('data.required', false);
        $this->patchJson($this->url.'/install', ['required' => true])->assertOk()->assertJsonPath('data.required', true);
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.name', 'install')->assertJsonPath('data.1.name', 'cache');
        $this->deleteJson($this->url.'/install')->assertOk()->assertJsonPath('data.required', true);
        $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.required', false);
        expect(ProjectDevelopmentDeployStep::query()->value('position'))->toBe(0);
        foreach (['create', 'update', 'destroy'] as $operation) {
            $activity = Activity::query()->where('command', 'project:dev-deploy-step:'.$operation)->latest('id')->firstOrFail();
            expect(json_encode($activity->properties))->not->toContain('composer', 'artisan');
        }
    });

    it('keeps Project development setup and teardown lists independent', function (): void {
        foreach (['setup-steps', 'teardown-steps', 'dev-deploy-steps'] as $list) {
            $this->postJson("/api/v1/projects/{$this->project->id}/{$list}", ['name' => 'install', 'command' => $list])->assertCreated();
        }
        $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://example.test/other.git', 'default_branch' => 'main']);
        $this->getJson("/api/v1/projects/{$other->id}/dev-deploy-steps")->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/projects/{$other->id}/dev-deploy-steps/install", ['required' => false])->assertNotFound();
        $this->deleteJson("/api/v1/projects/{$other->id}/dev-deploy-steps/install")->assertNotFound();
        $this->deleteJson($this->url.'/install')->assertOk();
        $this->getJson("/api/v1/projects/{$this->project->id}/setup-steps")->assertJsonCount(1, 'data');
        $this->project->delete();
        expect(ProjectDevelopmentDeployStep::query()->count())->toBe(0);
    });

    it('refuses invalid creation without persisting anything', function (array $payload): void {
        $this->postJson($this->url, $payload)->assertUnprocessable();
        expect(ProjectDevelopmentDeployStep::query()->count())->toBe(0);
    })->with([
        'missing command' => [['name' => 'install']],
        'invalid name' => [['name' => 'Bad_Name', 'command' => 'true']],
        'long name' => [['name' => str_repeat('a', 64), 'command' => 'true']],
        'empty command' => [['name' => 'install', 'command' => '']],
        'NUL command' => [['name' => 'install', 'command' => "a\0b"]],
        'long command' => [['name' => 'install', 'command' => str_repeat('a', 16385)]],
        'string timeout' => [['name' => 'install', 'command' => 'true', 'timeout_seconds' => '90']],
        'zero timeout' => [['name' => 'install', 'command' => 'true', 'timeout_seconds' => 0]],
        'large timeout' => [['name' => 'install', 'command' => 'true', 'timeout_seconds' => 901]],
        'null required' => [['name' => 'install', 'command' => 'true', 'required' => null]],
        'string required' => [['name' => 'install', 'command' => 'true', 'required' => 'false']],
        'integer required' => [['name' => 'install', 'command' => 'true', 'required' => 0]],
        'production phase' => [['name' => 'install', 'command' => 'true', 'phase' => 'before_activation']],
        'unknown placement' => [['name' => 'install', 'command' => 'true', 'before' => 'unknown']],
        'both placements' => [['name' => 'install', 'command' => 'true', 'before' => 'one', 'after' => 'two']],
    ]);

    it('refuses malformed JSON nonobject bodies and duplicate members', function (string $body): void {
        $this->call('POST', $this->url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertUnprocessable();
        expect(ProjectDevelopmentDeployStep::query()->count())->toBe(0);
    })->with(['[]', '{', '{"name":"install","command":"true","required":true,"required":false}', '{"name":"install","command":"\u0000"}']);

    it('refuses invalid updates atomically', function (array $payload): void {
        $this->postJson($this->url, ['name' => 'install', 'command' => 'true'])->assertCreated();
        $this->patchJson($this->url.'/install', $payload)->assertUnprocessable();
        $this->getJson($this->url)->assertJsonPath('data.0.command', 'true')->assertJsonPath('data.0.required', true)->assertJsonPath('data.0.timeout_seconds', 300);
    })->with([
        'empty' => [[]], 'rename' => [['name' => 'other']], 'empty command' => [['command' => '']],
        'timeout' => [['timeout_seconds' => 901]], 'null timeout' => [['timeout_seconds' => null]],
        'required string' => [['required' => 'true']], 'required null' => [['required' => null]],
        'self placement' => [['before' => 'install']], 'unknown placement' => [['after' => 'unknown']],
        'both placements' => [['before' => 'install', 'after' => 'install']],
    ]);

    it('refuses duplicate names missing steps and timeout total violations', function (): void {
        foreach (range(1, 4) as $n) {
            $this->postJson($this->url, ['name' => 'step-'.$n, 'command' => 'true', 'timeout_seconds' => 900])->assertCreated();
        }
        $this->postJson($this->url, ['name' => 'step-1', 'command' => 'false'])->assertUnprocessable();
        $this->postJson($this->url, ['name' => 'extra', 'command' => 'true', 'timeout_seconds' => 1])->assertUnprocessable();
        $this->patchJson($this->url.'/missing', ['required' => false])->assertNotFound()->assertJsonPath('error.code', 'development_deploy_step.not_found');
        $this->deleteJson($this->url.'/missing')->assertNotFound();
        $this->patchJson($this->url.'/step-1', ['timeout_seconds' => 899])->assertOk();
        $this->postJson($this->url, ['name' => 'extra', 'command' => 'true', 'timeout_seconds' => 1])->assertCreated();
        $this->patchJson($this->url.'/step-1', ['timeout_seconds' => 900])->assertUnprocessable();
        expect(ProjectDevelopmentDeployStep::query()->sum('timeout_seconds'))->toBe(3600);
    });

    it('refuses more than 32 steps and permits removal at the limit', function (): void {
        foreach (range(1, 32) as $n) {
            $this->postJson($this->url, ['name' => 'step-'.$n, 'command' => 'true', 'timeout_seconds' => 1])->assertCreated();
        }
        $this->postJson($this->url, ['name' => 'extra', 'command' => 'true', 'timeout_seconds' => 1])->assertUnprocessable();
        $this->deleteJson($this->url.'/step-1')->assertOk();
        $this->postJson($this->url, ['name' => 'extra', 'command' => 'true', 'timeout_seconds' => 1])->assertCreated();
    });

    it('requires directed access to the Project owning Node for every operation', function (): void {
        $owner = Node::query()->create(['name' => 'owner', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
        $peer = Node::query()->create(['name' => 'peer', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3']);
        Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $owner->id, 'name' => 'default', 'checkout_path' => '/srv/acme', 'status' => InstanceState::Active]);
        $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip]);
        $this->getJson($this->url)->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        $this->postJson($this->url, ['name' => 'install', 'command' => 'true'])->assertForbidden();
        $this->patchJson($this->url.'/install', ['required' => false])->assertForbidden();
        $this->deleteJson($this->url.'/install')->assertForbidden();
        NodeAccess::query()->create(['consumer_node_id' => $peer->id, 'serving_node_id' => $owner->id]);
        $this->postJson($this->url, ['name' => 'install', 'command' => 'true'])->assertCreated();
        $this->getJson($this->url)->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson($this->url.'/install', ['required' => false])->assertOk();
        $this->deleteJson($this->url.'/install')->assertOk();
    });

    it('refuses unauthenticated callers for every operation', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.100']);
        $this->getJson($this->url)->assertForbidden();
        $this->postJson($this->url, ['name' => 'install', 'command' => 'true'])->assertForbidden();
        $this->patchJson($this->url.'/install', ['required' => false])->assertForbidden();
        $this->deleteJson($this->url.'/install')->assertForbidden();
        expect(ProjectDevelopmentDeployStep::query()->count())->toBe(0);
    });
});
