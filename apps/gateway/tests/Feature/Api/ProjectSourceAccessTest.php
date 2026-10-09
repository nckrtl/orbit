<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Node;
use App\Models\Project;

beforeEach(function (): void {
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->branches = new class implements RepositoryDefaultBranchResolver
    {
        /** @var list<array{call: string, repository: string, source: string}> */
        public array $reads = [];

        public ?string $failure = null;

        public function resolve(string $repository, ProjectSourceAccess $source): string
        {
            $this->record('resolve', $repository, $source);

            return 'main';
        }

        public function verify(string $repository, string $branch, ProjectSourceAccess $source): void
        {
            $this->record('verify', $repository, $source);
        }

        private function record(string $call, string $repository, ProjectSourceAccess $source): void
        {
            $this->reads[] = ['call' => $call, 'repository' => $repository, 'source' => $source->value];

            if ($this->failure !== null) {
                throw new ResourceOperationException($this->failure, 'The read failed.');
            }
        }
    };
    app()->instance(RepositoryDefaultBranchResolver::class, $this->branches);
});

function sourceAccessProject(array $attributes = []): Project
{
    return Project::query()->create([
        'name' => 'leden',
        'slug' => 'leden',
        'repository_url' => 'https://github.com/Dutch-Laravel-Foundation/leden.git',
        'default_branch' => null,
        'apps' => fixture_apps('public'),
        ...$attributes,
    ]);
}

describe('Project source access', function (): void {
    it('defaults to github_app and reads through the App path', function (): void {
        $this->postJson('/api/v1/projects', [
            'slug' => 'acme',
            'type' => 'laravel-app',
            'repository_url' => 'https://github.com/acme/site.git',
            'apps' => fixture_apps('public', 'laravel-app'),
        ])
            ->assertCreated()
            ->assertJsonPath('data.source_access', 'github_app');

        expect($this->branches->reads)->toBe([
            ['call' => 'resolve', 'repository' => 'https://github.com/acme/site.git', 'source' => 'github_app'],
        ]);
    });

    it('creates a gh_cli Project that reads through the GitHub CLI and keeps it on an exact retry', function (): void {
        $payload = [
            'slug' => 'leden',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:Dutch-Laravel-Foundation/leden.git',
            'source_access' => 'gh_cli',
            'apps' => fixture_apps('public'),
        ];

        $created = $this->postJson('/api/v1/projects', $payload)
            ->assertCreated()
            ->assertJsonPath('data.source_access', 'gh_cli')
            ->assertJsonPath('data.default_branch', 'main');
        $this->postJson('/api/v1/projects', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $created->json('data.id'));
        $this->postJson('/api/v1/projects', [...$payload, 'source_access' => 'github_app'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.identity_conflict');

        expect($this->branches->reads)->toBe([
            ['call' => 'resolve', 'repository' => 'git@github.com:Dutch-Laravel-Foundation/leden.git', 'source' => 'gh_cli'],
        ])->and(Project::query()->sole()->source_access)->toBe(ProjectSourceAccess::GhCli);
    });

    it('refuses gh_cli for a repository outside github.com', function (): void {
        $this->postJson('/api/v1/projects', [
            'slug' => 'acme',
            'type' => 'laravel-app',
            'repository_url' => 'https://gitlab.com/acme/site.git',
            'source_access' => 'gh_cli',
            'apps' => fixture_apps('public', 'laravel-app'),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => ['source_access']]]);

        $project = sourceAccessProject(['source_access' => ProjectSourceAccess::GhCli]);
        $this->patchJson("/api/v1/projects/{$project->id}", ['repository_url' => 'https://gitlab.com/acme/site.git'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['source_access']]]);

        expect(Project::query()->count())->toBe(1)
            ->and($project->refresh()->repository_url)->toBe('https://github.com/Dutch-Laravel-Foundation/leden.git')
            ->and($this->branches->reads)->toBe([]);
    });

    it('switches a Project to gh_cli and verifies the default branch through the GitHub CLI', function (): void {
        $project = sourceAccessProject();

        $this->patchJson("/api/v1/projects/{$project->id}", ['source_access' => 'gh_cli', 'default_branch' => 'main'])
            ->assertOk()
            ->assertJsonPath('data.source_access', 'gh_cli')
            ->assertJsonPath('data.default_branch', 'main');

        expect($this->branches->reads)->toBe([
            ['call' => 'resolve', 'repository' => 'https://github.com/Dutch-Laravel-Foundation/leden.git', 'source' => 'gh_cli'],
            ['call' => 'verify', 'repository' => 'https://github.com/Dutch-Laravel-Foundation/leden.git', 'source' => 'gh_cli'],
        ]);
    });

    it('changes nothing when the repository cannot be read with the new source access', function (string $code): void {
        $project = sourceAccessProject();
        $this->branches->failure = $code;

        $this->patchJson("/api/v1/projects/{$project->id}", ['source_access' => 'gh_cli'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', $code);

        expect($project->refresh()->source_access)->toBe(ProjectSourceAccess::GitHubApp);
    })->with(['github.cli_unauthenticated', 'project.default_branch_unavailable']);

    it('updates source access separately from the Project code', function (): void {
        $project = sourceAccessProject();

        $this->patchJson("/api/v1/projects/{$project->id}", ['source_access' => 'gh_cli', 'code' => 'LED'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'project.code_update_separate');

        expect($project->refresh()->source_access)->toBe(ProjectSourceAccess::GitHubApp);
    });
});
