<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\NativeRepositoryDefaultBranchResolver;
use App\Models\App as OrbitApp;
use App\Models\Node;

beforeEach(function (): void {
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->branches = new class implements RepositoryDefaultBranchResolver {
        /** @var list<string> */
        public array $resolvedRepositories = [];

        /** @var list<array{repository: string, branch: string}> */
        public array $verifiedBranches = [];

        public string $defaultBranch = 'trunk';

        public bool $available = true;

        public function resolve(string $repository): string
        {
            $this->resolvedRepositories[] = $repository;
            $this->assertAvailable();

            return $this->defaultBranch;
        }

        public function verify(string $repository, string $branch): void
        {
            $this->verifiedBranches[] = ['repository' => $repository, 'branch' => $branch];
            $this->assertAvailable();
        }

        private function assertAvailable(): void
        {
            if (! $this->available) {
                throw new ResourceOperationException(
                    'app.default_branch_unavailable',
                    'The requested repository branch could not be determined or verified.',
                );
            }
        }
    };
    app()->instance(RepositoryDefaultBranchResolver::class, $this->branches);
});

it('stores explicit source defaults and returns them through every App response', function (): void {
    $created = $this->postJson('/api/v1/apps', [
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'main_branch' => 'stable',
        'root' => 'web/public',
        'defaults' => ['php_version' => '8.5'],
    ]);

    $created
        ->assertCreated()
        ->assertJsonPath('data.repository_url', 'https://github.com/acme/site.git')
        ->assertJsonPath('data.main_branch', 'stable')
        ->assertJsonPath('data.root', 'web/public');
    $appId = $created->json('data.id');
    $this
        ->getJson('/api/v1/apps')
        ->assertOk()
        ->assertJsonPath('data.0.main_branch', 'stable')
        ->assertJsonPath('data.0.root', 'web/public');
    $this
        ->getJson("/api/v1/apps/{$appId}")
        ->assertOk()
        ->assertJsonPath('data.main_branch', 'stable')
        ->assertJsonPath('data.root', 'web/public');

    expect($this->branches->resolvedRepositories)
        ->toBeEmpty()
        ->and($this->branches->verifiedBranches)
        ->toBe([[
            'repository' => 'https://github.com/acme/site.git',
            'branch' => 'stable',
        ]])
        ->and(OrbitApp::query()->sole()->only(['repository_url', 'main_branch', 'root']))
        ->toBe([
            'repository_url' => 'https://github.com/acme/site.git',
            'main_branch' => 'stable',
            'root' => 'web/public',
        ]);
});

it('resolves an omitted main branch once and returns the existing App on an exact retry', function (): void {
    $payload = [
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'root' => 'public',
        'defaults' => ['php_version' => '8.5'],
    ];

    $created = $this
        ->postJson('/api/v1/apps', $payload)
        ->assertCreated()
        ->assertJsonPath('data.main_branch', 'trunk');
    $this->branches->defaultBranch = 'renamed-default';
    $retried = $this
        ->postJson('/api/v1/apps', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.main_branch', 'trunk');

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
        'repository_url' => 'https://github.com/acme/site.git',
        'main_branch' => 'main',
        'root' => 'public',
        'defaults' => ['php_version' => '8.5'],
    ];

    $this->postJson('/api/v1/apps', $payload)->assertCreated();
    $this->branches->verifiedBranches = [];

    $this
        ->postJson('/api/v1/apps', [...$payload, ...$changes])
        ->assertConflict()
        ->assertJsonPath('error.code', 'app.identity_conflict');

    expect(OrbitApp::query()
        ->sole()
        ->only([
            'name',
            'repository_url',
            'main_branch',
            'root',
            'defaults',
        ]))
        ->toBe([
            'name' => 'Acme',
            'repository_url' => 'https://github.com/acme/site.git',
            'main_branch' => 'main',
            'root' => 'public',
            'defaults' => ['php_version' => '8.5'],
        ])
        ->and($this->branches->verifiedBranches)
        ->toBeEmpty();
})->with([
    'repository' => [['repository_url' => 'https://github.com/acme/other.git']],
    'equivalent repository access URL' => [[
        'repository_url' => 'ssh://git@github.com/acme/site.git',
    ]],
    'main branch' => [['main_branch' => 'stable']],
    'root' => [['root' => 'web']],
    'name' => [['name' => 'Renamed']],
    'defaults' => [['defaults' => ['php_version' => '8.4']]],
]);

it('returns null source defaults truthfully for a legacy App', function (): void {
    $app = OrbitApp::query()->create([
        'name' => 'Legacy',
        'slug' => 'legacy',
        'repository_url' => 'https://github.com/acme/legacy.git',
        'main_branch' => null,
        'root' => null,
    ]);

    $this
        ->getJson("/api/v1/apps/{$app->id}")
        ->assertOk()
        ->assertJsonPath('data.main_branch', null)
        ->assertJsonPath('data.root', null);

    expect($app->refresh()->only(['main_branch', 'root']))
        ->toBe(['main_branch' => null, 'root' => null]);
});

it('rejects invalid or incomplete source defaults without persistence', function (array $payload): void {
    $this->postJson('/api/v1/apps', $payload)->assertUnprocessable();

    expect(OrbitApp::query()->count())->toBe(0);
})->with([
    'missing repository' => [[
        'slug' => 'acme',
        'main_branch' => 'main',
        'root' => 'public',
    ]],
    'missing root' => [[
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'main_branch' => 'main',
    ]],
    'invalid branch' => [[
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'main_branch' => '../main',
        'root' => 'public',
    ]],
    'absolute root' => [[
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'main_branch' => 'main',
        'root' => '/public',
    ]],
    'traversing root' => [[
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'main_branch' => 'main',
        'root' => '../public',
    ]],
    'leading dot segment' => [[
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'main_branch' => 'main',
        'root' => './public',
    ]],
    'nested dot segment' => [[
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'main_branch' => 'main',
        'root' => 'public/./assets',
    ]],
    'empty root' => [[
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'main_branch' => 'main',
        'root' => '',
    ]],
]);

it('rejects an unavailable explicit or default branch with one stable error', function (?string $mainBranch): void {
    $this->branches->available = false;
    $repository = 'https://example.test/private-repository.git';
    $payload = [
        'slug' => 'acme',
        'repository_url' => $repository,
        'root' => 'public',
    ];

    if ($mainBranch !== null) {
        $payload['main_branch'] = $mainBranch;
    }

    $response = $this
        ->postJson('/api/v1/apps', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'app.default_branch_unavailable')
        ->assertJsonPath(
            'error.message',
            'The requested repository branch could not be determined or verified.',
        );

    expect($response->getContent())
        ->not
        ->toContain($repository)
        ->and(OrbitApp::query()->exists())
        ->toBeFalse();
})->with([
    'omitted main branch' => [null],
    'explicit main branch' => ['main'],
]);

it('returns 422 without persistence when the remote default branch is malformed UTF-8', function (): void {
    $processes = new class implements ProcessRunner {
        public function run(ProcessInvocation $invocation): CommandResult
        {
            return new CommandResult(0, "ref: refs/heads/bad-\xC3\x28\tHEAD\n", '', 1, false);
        }
    };
    app()->instance(
        RepositoryDefaultBranchResolver::class,
        new NativeRepositoryDefaultBranchResolver($processes),
    );

    $this
        ->postJson('/api/v1/apps', [
            'slug' => 'acme',
            'repository_url' => 'https://github.com/acme/site.git',
            'root' => 'public',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'app.default_branch_unavailable');

    expect(OrbitApp::query()->exists())->toBeFalse();
});

it('rejects unsupported and duplicate App source keys', function (string $body): void {
    $this
        ->withHeader('Content-Type', 'application/json')
        ->call('POST', '/api/v1/apps', content: $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(OrbitApp::query()->count())->toBe(0);
})->with([
    'unsupported key' => [
        '{"slug":"acme","repository_url":"https://github.com/acme/site.git","main_branch":"main","root":"public","command":"id"}',
    ],
    'duplicate repository' => [
        '{"slug":"acme","repository_url":"https://github.com/acme/site.git","repository_url":"https://github.com/acme/other.git","main_branch":"main","root":"public"}',
    ],
]);
