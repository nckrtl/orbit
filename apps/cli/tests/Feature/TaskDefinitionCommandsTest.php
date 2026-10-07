<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tasks\CreateTaskDefinitionRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskDefinitionsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskDefinitionRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskDefinitionRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

/**
 * Proves the five task definition commands: local refusals, the recorded Gateway responses,
 * and the definition file sent as the request body.
 */
beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    mkdir($this->orbitHome, 0700, true);
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $this->definitionFile = $this->orbitHome.'/definition.json';
    file_put_contents($this->definitionFile, definition_command_json());
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

describe('task definition command input', function (): void {
    it('refuses omitted and invalid input before any request', function (string $command, array $arguments, string $code): void {
        foreach ([['--json' => true], ['--no-interaction' => true]] as $mode) {
            $mock = MockClient::global([]);

            expect(Artisan::call($command, [...$arguments, ...$mode]))->toBe(1);

            if (isset($mode['--json'])) {
                expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
            } else {
                expect(Artisan::output())->not->toBe('');
            }

            $mock->assertNothingSent();
            MockClient::destroyGlobal();
        }
    })->with([
        'list with an invalid project' => ['tasks:definition:list', ['--project' => '0'], 'tasks.project_invalid'],
        'show without a project' => ['tasks:definition:show', ['name' => 'build-feature'], 'tasks.project_required'],
        'show without a name' => ['tasks:definition:show', ['--project' => '1'], 'tasks.definition_name_required'],
        'show with an invalid name' => ['tasks:definition:show', ['name' => 'Build', '--project' => '1'], 'tasks.definition_name_invalid'],
        'create without a project' => ['tasks:definition:create', ['--definition' => 'definition.json'], 'tasks.project_required'],
        'create without a file' => ['tasks:definition:create', ['--project' => '1'], 'tasks.definition_file_required'],
        'update without a name' => ['tasks:definition:update', ['--project' => '1', '--definition' => 'definition.json'], 'tasks.definition_name_required'],
        'destroy without consent' => ['tasks:definition:destroy', ['name' => 'build-feature', '--project' => '1'], 'input.confirmation_required'],
    ]);

    it('refuses a definition file that cannot be read or is not a JSON object', function (string $contents): void {
        file_put_contents($this->definitionFile, $contents);
        $mock = MockClient::global([]);

        expect(Artisan::call('tasks:definition:create', [
            '--project' => '1',
            '--definition' => $contents === 'missing' ? $this->orbitHome.'/missing.json' : $this->definitionFile,
            '--json' => true,
        ]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('tasks.definition_file_invalid');

        $mock->assertNothingSent();
    })->with([
        'missing' => ['missing'],
        'not JSON' => ['name: build-feature'],
        'a list' => ['[{"name":"build-feature"}]'],
        'a string' => ['"build-feature"'],
    ]);

    it('refuses an update whose file name differs from the argument', function (): void {
        $mock = MockClient::global([]);

        expect(Artisan::call('tasks:definition:update', [
            'name' => 'other-feature',
            '--project' => '1',
            '--definition' => $this->definitionFile,
            '--json' => true,
        ]))->toBe(1)
            ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('tasks.definition_name_mismatch');

        $mock->assertNothingSent();
    });
});

describe('task definition command contract', function (): void {
    it('renders the definition list and an empty list', function (): void {
        run_definition_contract('tasks-definition-list/default', 'tasks:definition:list', ['--project' => '1'], 0);
        run_definition_contract('tasks-definition-list/empty', 'tasks:definition:list', [], 0);
    });

    it('renders one definition and a missing definition', function (): void {
        run_definition_contract('tasks-definition-show/default', 'tasks:definition:show', ['name' => 'build-feature', '--project' => '1'], 0);
        run_definition_contract('tasks-definition-show/missing', 'tasks:definition:show', ['name' => 'missing', '--project' => '1'], 1);
    });

    it('renders a created definition, a refused invalid definition, and a name that already exists', function (): void {
        $arguments = ['--project' => '1', '--definition' => $this->definitionFile];

        run_definition_contract('tasks-definition-create/created', 'tasks:definition:create', $arguments, 0);
        run_definition_contract('tasks-definition-create/invalid', 'tasks:definition:create', $arguments, 1);
        run_definition_contract('tasks-definition-create/exists', 'tasks:definition:create', $arguments, 1);
    });

    it('renders a replaced definition and a deleted definition', function (): void {
        run_definition_contract('tasks-definition-update/updated', 'tasks:definition:update', [
            'name' => 'build-feature',
            '--project' => '1',
            '--definition' => $this->definitionFile,
        ], 0);
        run_definition_contract('tasks-definition-destroy/destroyed', 'tasks:definition:destroy', [
            'name' => 'build-feature',
            '--project' => '1',
            '--yes' => true,
        ], 0);
    });

    it('sends the definition file as the create and update body', function (): void {
        $record = [
            'project_id' => 1,
            'name' => 'build-feature',
            'title' => 'Build a feature',
            'brief' => 'Document and build it.',
            'parameters' => [],
            'status' => 'backlog',
            'schedule' => null,
            'phases' => [],
            'subtasks' => [['key' => 'docs', 'title' => 'Write the docs', 'kind' => 'agent']],
        ];
        $mock = MockClient::global([
            ...gateway_fixture_mock(),
            CreateTaskDefinitionRequest::class => MockResponse::make([
                'data' => $record,
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ], 201),
            UpdateTaskDefinitionRequest::class => MockResponse::make([
                'data' => $record,
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ]),
            ListTaskDefinitionsRequest::class => MockResponse::make([
                'data' => [],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ]),
        ]);

        expect(Artisan::call('tasks:definition:create', [
            '--project' => '1',
            '--definition' => $this->definitionFile,
            '--json' => true,
        ]))->toBe(0)
            ->and(Artisan::call('tasks:definition:update', [
                'name' => 'build-feature',
                '--project' => '1',
                '--definition' => $this->definitionFile,
                '--json' => true,
            ]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof CreateTaskDefinitionRequest
            && (string) $request->body() === definition_command_json()
            && $request->resolveEndpoint() === '/api/v1/projects/1/task-definitions');
        $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskDefinitionRequest
            && (string) $request->body() === definition_command_json()
            && $request->resolveEndpoint() === '/api/v1/projects/1/task-definitions/build-feature');
    });

    it('keeps a nested empty object through show and a file update', function (): void {
        $body = <<<'JSON'
{"data":{"project_id":1,"name":"build-feature","title":"Build a feature","brief":"Document and build it.","parameters":[{"name":"tuning","type":"text","required":false,"default":{"options":{},"flags":[]}}],"status":"backlog","schedule":null,"phases":[],"subtasks":[{"key":"ship","title":"Ship it","kind":"action","operation":"instance:deploy","arguments":{"options":{},"flags":[]}}]},"meta":{"request_id":"0198e15c-bf97-7c23-8f1f-61b8fe67a844"}}
JSON;
        $mock = MockClient::global([
            ...gateway_fixture_mock(),
            ShowTaskDefinitionRequest::class => MockResponse::make($body, 200, ['Content-Type' => 'application/json']),
            UpdateTaskDefinitionRequest::class => MockResponse::make($body, 200, ['Content-Type' => 'application/json']),
        ]);

        expect(Artisan::call('tasks:definition:show', [
            'name' => 'build-feature',
            '--project' => '1',
            '--json' => true,
        ]))->toBe(0);

        $shown = Artisan::output();
        file_put_contents($this->definitionFile, $shown);

        expect($shown)->toContain('"default":{"options":{},"flags":[]}')
            ->and($shown)->toContain('"arguments":{"options":{},"flags":[]}')
            ->and(Artisan::call('tasks:definition:update', [
                'name' => 'build-feature',
                '--project' => '1',
                '--definition' => $this->definitionFile,
                '--json' => true,
            ]))->toBe(0);

        $mock->assertSent(static fn (Request $request): bool => $request instanceof UpdateTaskDefinitionRequest
            && str_contains((string) $request->body(), '"default":{"options":{},"flags":[]}')
            && str_contains((string) $request->body(), '"arguments":{"options":{},"flags":[]}'));
    });

});

/** @param array<string, mixed> $arguments */
function run_definition_contract(string $fixture, string $command, array $arguments, int $exitCode): void
{
    [$family, $case] = explode('/', $fixture, 2);

    foreach (['human.txt' => [], 'json' => ['--json' => true]] as $extension => $mode) {
        MockClient::destroyGlobal();
        MockClient::global(gateway_fixture_mock("tasks/{$fixture}"));

        expect(Artisan::call($command, [...$arguments, ...$mode]))->toBe($exitCode);
        expect_output(Artisan::output(), "tasks/{$family}/{$case}.{$extension}");
    }
}

function definition_command_json(): string
{
    return '{"name":"build-feature","title":"Build a feature","brief":"Document and build it.","parameters":[],"status":"backlog","subtasks":[{"key":"docs","title":"Write the docs","kind":"agent"}]}';
}
