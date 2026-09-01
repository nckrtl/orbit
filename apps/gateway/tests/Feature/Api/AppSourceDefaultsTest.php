<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
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
        public array $repositories = [];

        public string $branch = 'trunk';

        public function resolve(string $repository): string
        {
            $this->repositories[] = $repository;

            return $this->branch;
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
    ]);

    $created
        ->assertCreated()
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

    expect($this->branches->repositories)
        ->toBeEmpty()
        ->and(OrbitApp::query()->sole()->only(['main_branch', 'root']))
        ->toBe(['main_branch' => 'stable', 'root' => 'web/public']);
});

it('resolves an omitted main branch once and does not rewrite it on retry', function (): void {
    $payload = [
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'root' => 'public',
    ];

    $this
        ->postJson('/api/v1/apps', $payload)
        ->assertCreated()
        ->assertJsonPath('data.main_branch', 'trunk');
    $this->branches->branch = 'renamed-default';
    $this
        ->postJson('/api/v1/apps', $payload)
        ->assertOk()
        ->assertJsonPath('data.main_branch', 'trunk');

    expect($this->branches->repositories)
        ->toBe(['https://github.com/acme/site.git'])
        ->and(OrbitApp::query()->sole()->main_branch)
        ->toBe('trunk');
});

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
});

it('rejects invalid or incomplete source defaults without persisting an App', function (array $payload): void {
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
]);

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
