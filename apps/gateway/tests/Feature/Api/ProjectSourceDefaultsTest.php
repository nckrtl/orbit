<?php

declare(strict_types=1);

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\NativeRepositoryDefaultBranchResolver;
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
        /** @var list<string> */
        public array $resolvedRepositories = [];

        /** @var list<array{repository: string, branch: string}> */
        public array $verifiedBranches = [];

        public string $defaultBranch = 'trunk';

        public bool $available = true;

        public function resolve(string $repository, ProjectSourceAccess $source): string
        {
            $this->resolvedRepositories[] = $repository;
            $this->assertAvailable();

            return $this->defaultBranch;
        }

        public function verify(string $repository, string $branch, ProjectSourceAccess $source): void
        {
            $this->verifiedBranches[] = ['repository' => $repository, 'branch' => $branch];
            $this->assertAvailable();
        }

        private function assertAvailable(): void
        {
            if (! $this->available) {
                throw new ResourceOperationException(
                    'project.default_branch_unavailable',
                    'The requested repository branch could not be determined or verified.',
                );
            }
        }
    };
    app()->instance(RepositoryDefaultBranchResolver::class, $this->branches);
});

it('stores explicit source defaults and returns them through every App response', function (): void {
    $created = $this->postJson('/api/v1/projects', [
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'stable',
        'apps' => fixture_apps('web/public', 'laravel-app'),
    ]);

    $created
        ->assertCreated()
        ->assertJsonPath('data.repository_url', 'https://github.com/acme/site.git')
        ->assertJsonPath('data.default_branch', 'stable')
        ->assertJsonPath('data.apps', fixture_apps('web/public'));
    $projectId = $created->json('data.id');
    $this
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonPath('data.0.default_branch', 'stable')
        ->assertJsonPath('data.0.apps', fixture_apps('web/public'));
    $this
        ->getJson("/api/v1/projects/{$projectId}")
        ->assertOk()
        ->assertJsonPath('data.default_branch', 'stable')
        ->assertJsonPath('data.apps', fixture_apps('web/public'));

    expect($this->branches->resolvedRepositories)
        ->toBeEmpty()
        ->and($this->branches->verifiedBranches)
        ->toBe([[
            'repository' => 'https://github.com/acme/site.git',
            'branch' => 'stable',
        ]])
        ->and(Project::query()->sole()->only(['repository_url', 'default_branch', 'apps']))
        ->toBe([
            'repository_url' => 'https://github.com/acme/site.git',
            'default_branch' => 'stable',
            'apps' => fixture_apps('web/public'),
        ]);
});

it('resolves an omitted default branch once and returns the existing Project on an exact retry', function (): void {
    $payload = [
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'apps' => fixture_apps('public'),
    ];

    $created = $this
        ->postJson('/api/v1/projects', $payload)
        ->assertCreated()
        ->assertJsonPath('data.default_branch', 'trunk');
    $this->branches->defaultBranch = 'renamed-default';
    $retried = $this
        ->postJson('/api/v1/projects', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.default_branch', 'trunk');

    expect($retried->json('data'))
        ->toBe($created->json('data'))
        ->and($this->branches->resolvedRepositories)
        ->toBe(['https://github.com/acme/site.git'])
        ->and($this->branches->verifiedBranches)
        ->toBeEmpty();
});

it('rejects conflicting creation identity without mutation or remote access', function (array $changes): void {
    $payload = [
        'name' => 'Acme',
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ];

    $this->postJson('/api/v1/projects', $payload)->assertCreated();
    $this->branches->verifiedBranches = [];

    $this
        ->postJson('/api/v1/projects', [...$payload, ...$changes])
        ->assertConflict()
        ->assertJsonPath('error.code', 'project.identity_conflict');

    expect(Project::query()
        ->sole()
        ->only([
            'name',
            'repository_url',
            'default_branch',
            'apps',
        ]))
        ->toBe([
            'name' => 'Acme',
            'repository_url' => 'https://github.com/acme/site.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ])
        ->and($this->branches->verifiedBranches)
        ->toBeEmpty();
})->with([
    'repository' => [['repository_url' => 'https://github.com/acme/other.git']],
    'equivalent repository access URL' => [[
        'repository_url' => 'ssh://git@github.com/acme/site.git',
    ]],
    'default branch' => [['default_branch' => 'stable']],
    'apps' => [['apps' => fixture_apps('web')]],
    'name' => [['name' => 'Renamed']],
]);

it('returns null source defaults truthfully for a legacy App', function (): void {
    $project = Project::query()->create([
        'name' => 'Legacy',
        'slug' => 'legacy',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/legacy.git',
        'default_branch' => null,
        'apps' => fixture_apps(null, 'laravel-app'),
    ]);

    $this
        ->getJson("/api/v1/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.default_branch', null)
        ->assertJsonPath('data.apps', fixture_apps('public'));

    expect($project->refresh()->default_branch)->toBeNull();
});

it('rejects invalid or incomplete source defaults without persistence', function (array $payload): void {
    $this->postJson('/api/v1/projects', $payload)->assertUnprocessable();

    expect(Project::query()->count())->toBe(0);
})->with([
    'missing repository' => [[
        'slug' => 'acme',
        'type' => 'laravel-app',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]],
    'missing apps' => [[
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
    ]],
    'invalid branch' => [[
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => '../main',
        'apps' => fixture_apps('public'),
    ]],
    'absolute app path' => [[
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'apps' => [['name' => 'web', 'path' => '/public', 'web_root' => null, 'type' => 'laravel-app']],
    ]],
    'traversing app path' => [[
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'apps' => [['name' => 'web', 'path' => '../public', 'web_root' => null, 'type' => 'laravel-app']],
    ]],
    'leading dot segment' => [[
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'apps' => [['name' => 'web', 'path' => './public', 'web_root' => null, 'type' => 'laravel-app']],
    ]],
    'nested dot segment' => [[
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'apps' => [['name' => 'web', 'path' => 'public/./assets', 'web_root' => null, 'type' => 'laravel-app']],
    ]],
    'empty app path' => [[
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'apps' => [['name' => 'web', 'path' => '', 'web_root' => null, 'type' => 'laravel-app']],
    ]],
]);

it('rejects an unavailable explicit or default branch with one stable error', function (?string $defaultBranch): void {
    $this->branches->available = false;
    $repository = 'https://example.test/private-repository.git';
    $payload = [
        'slug' => 'acme',
        'type' => 'laravel-app',
        'repository_url' => $repository,
        'apps' => fixture_apps('public'),
    ];

    if ($defaultBranch !== null) {
        $payload['default_branch'] = $defaultBranch;
    }

    $response = $this
        ->postJson('/api/v1/projects', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'project.default_branch_unavailable')
        ->assertJsonPath(
            'error.message',
            'The requested repository branch could not be determined or verified.',
        );

    expect($response->getContent())
        ->not
        ->toContain($repository)
        ->and(Project::query()->exists())
        ->toBeFalse();
})->with([
    'omitted default branch' => [null],
    'explicit default branch' => ['main'],
]);

it('returns 422 without persistence when the remote default branch is malformed UTF-8', function (): void {
    $processes = new class implements ProcessRunner
    {
        public function run(ProcessInvocation $invocation): CommandResult
        {
            return new CommandResult(0, "ref: refs/heads/bad-\xC3\x28\tHEAD\n", '', 1, false);
        }
    };
    app()->instance(
        RepositoryDefaultBranchResolver::class,
        new NativeRepositoryDefaultBranchResolver($processes, app(RepositoryReadAccess::class)),
    );

    $this
        ->postJson('/api/v1/projects', [
            'slug' => 'acme',
            'type' => 'laravel-app',
            'repository_url' => 'https://github.com/acme/site.git',
            'apps' => fixture_apps('public', 'laravel-app'),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'project.default_branch_unavailable');

    expect(Project::query()->exists())->toBeFalse();
});

it('rejects unsupported and duplicate App source keys', function (string $body): void {
    $this
        ->withHeader('Content-Type', 'application/json')
        ->call('POST', '/api/v1/projects', content: $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(Project::query()->count())->toBe(0);
})->with([
    'unsupported key' => [
        '{"slug":"acme","repository_url":"https://github.com/acme/site.git","default_branch":"main","apps":[{"name":"web","path":".","web_root":"public","type":"laravel-app"}],"command":"id"}',
    ],
    'duplicate repository' => [
        '{"slug":"acme","repository_url":"https://github.com/acme/site.git","repository_url":"https://github.com/acme/other.git","default_branch":"main","apps":[{"name":"web","path":".","web_root":"public","type":"laravel-app"}]}',
    ],
]);
