<?php

declare(strict_types=1);

use App\Commands\GatewayCommand;
use App\Commands\Projects\Concerns\ResolvesDevelopmentNodeExclusions;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleMode;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orbit\Sdk\Requests\Apps\ListAppsRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Projects\AddProjectExcludedNodeRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-exclusions-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/tmp/test-ca.pem'));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('refuses omitted selectors without sending requests in machine mode', function (string $command, array $arguments, string $code): void {
    $mock = MockClient::global([]);

    expect(Artisan::call($command, [...$arguments, '--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($code);
    $mock->assertNothingSent();
})->with([
    ['project:excluded-node:add', [], 'app.id_invalid'],
    ['project:excluded-node:list', [], 'app.id_invalid'],
    ['project:excluded-node:remove', ['--project' => '4'], 'node.reference_required'],
    ['node:excluded-project:add', [], 'app.id_invalid'],
    ['node:excluded-project:list', [], 'node.reference_required'],
    ['node:excluded-project:remove', ['project' => '4'], 'node.reference_required'],
]);

it('rejects an invalid explicit Project without looking up choices', function (): void {
    $mock = MockClient::global([]);
    expect(Artisan::call('project:excluded-node:add', ['--project' => 'zero', '--json' => true]))->toBe(1);
    $mock->assertNothingSent();
});

it('uses stable selected IDs when adding an exclusion', function (): void {
    $mock = exclusion_selection_mock();
    $tester = exclusion_selection_tester([Key::DOWN, Key::ENTER, Key::DOWN, Key::ENTER]);

    expect($tester->execute([]))->toBe(0);
    $mock->assertSent(static fn (Request $request): bool => $request instanceof AddProjectExcludedNodeRequest
        && $request->resolveEndpoint() === '/api/v1/projects/42/excluded-nodes/94');
});

it('stops before mutation when either selection is canceled or has no records', function (array $keys, bool $emptyProjects, bool $emptyNodes): void {
    $mock = exclusion_selection_mock($emptyProjects, $emptyNodes);
    $tester = exclusion_selection_tester($keys);

    expect($tester->execute([]))->toBe(1);
    $mock->assertNotSent(AddProjectExcludedNodeRequest::class);
    expect($tester->getDisplay())->toContain($emptyProjects || $emptyNodes ? 'No matching records' : 'Input cancelled');
})->with([
    'cancel Project' => [[Key::CTRL_C], false, false],
    'cancel Node' => [[Key::ENTER, Key::CTRL_C], false, false],
    'no Projects' => [[], true, false],
    'no Nodes' => [[Key::ENTER], false, true],
]);

function exclusion_selection_mock(bool $emptyProjects = false, bool $emptyNodes = false): MockClient
{
    $meta = ['request_id' => '11111111-1111-4111-8111-111111111111'];

    return MockClient::global([
        ListAppsRequest::class => MockResponse::make(['data' => $emptyProjects ? [] : [
            ['id' => 7, 'name' => 'Alpha', 'slug' => 'alpha', 'type' => 'monorepo', 'repository_url' => 'https://example.test/alpha.git'],
            ['id' => 42, 'name' => 'Beta', 'slug' => 'beta', 'type' => 'monorepo', 'repository_url' => 'https://example.test/beta.git'],
        ], 'meta' => $meta]),
        ListNodesRequest::class => MockResponse::make(['data' => $emptyNodes ? [] : [
            ['id' => 17, 'name' => 'first', 'status' => 'active', 'roles' => ['app-dev']],
            ['id' => 94, 'name' => 'second', 'status' => 'active', 'roles' => ['app-dev']],
        ], 'meta' => $meta]),
        AddProjectExcludedNodeRequest::class => MockResponse::make(['data' => [
            'project_id' => 42, 'project_slug' => 'beta', 'node_id' => 94, 'node_name' => 'second', 'development_instance_count' => 0,
        ], 'meta' => $meta]),
    ]);
}

/** @param list<string> $keys */
function exclusion_selection_tester(array $keys): CommandTester
{
    $command = new ExclusionSelectionCommand(new ExclusionSelectionTerminal($keys));
    $command->setLaravel(app());

    return new CommandTester($command);
}

final class ExclusionSelectionCommand extends GatewayCommand
{
    use ResolvesDevelopmentNodeExclusions;

    protected $signature = 'test:exclusion-selection {--json}';

    public function __construct(private readonly Terminal $terminal)
    {
        parent::__construct();
    }

    protected function consoleMode(?OutputInterface $output = null): ConsoleMode
    {
        return new ConsoleMode(false, true, false, false, 100);
    }

    protected function commandPrompts(): CommandPrompts
    {
        return new CommandPrompts($this->consoleMode(), $this->output, $this->terminal);
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        return PromptContext::preserve(function () use ($repository, $factory): int {
            Prompt::fallbackWhen(false);
            if (! $this->validExclusionProjectInput(null)) {
                return self::FAILURE;
            }
            $connector = $this->gatewayConnector($repository, $factory);
            assert($connector !== null);
            $projectId = $this->resolveExclusionProjectId($connector, null);
            $nodeId = $this->resolveExclusionNodeId($connector, null);
            if ($projectId === null || $nodeId === null) {
                return self::FAILURE;
            }

            return $this->sendWithProgress($connector, new AddProjectExcludedNodeRequest($projectId, $nodeId), DevelopmentNodeExclusionResponse::class, ['Add', 'Adding', 'Added']) === null
                ? self::FAILURE : self::SUCCESS;
        });
    }
}

final class ExclusionSelectionTerminal extends Terminal
{
    /** @param list<string> $keys */
    public function __construct(private array $keys)
    {
        parent::__construct();
    }

    public function read(): string
    {
        return array_shift($this->keys) ?? throw new PromptAborted('Input ended.');
    }

    public function setTty(string $mode): void {}

    public function restoreTty(): void {}

    public function cols(): int
    {
        return 100;
    }

    public function lines(): int
    {
        return 40;
    }
}
