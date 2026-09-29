<?php

declare(strict_types=1);

use App\Commands\Routes\CreateRouteCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\CommandPrompts;
use App\Support\Console\ConsoleMode;
use App\Support\Console\PromptAborted;
use App\Support\Console\PromptContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Terminal;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-route-prompt-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('selects an omitted Instance by stable ID and sends the selected Instance in the create request', function (): void {
    $mock = route_prompt_mock();
    [$status, $display] = route_prompt_run(['domain' => 'shop.test'], [Key::DOWN, Key::ENTER]);

    expect($status)->toBe(0)
        ->and($display)->toContain('Instance', 'Orbit docs', 'Store')
        ->and($mock->getLastRequest()?->body()->all())->toBe([
            'domain' => 'shop.test',
            'publication' => 'private',
            'instance_id' => 12,
        ]);
    $mock->assertSent(ListInstancesRequest::class);
});

it('prompts for the omitted domain after an explicit Instance without listing Instances', function (): void {
    $mock = MockClient::global([CreateRouteRequest::class => route_prompt_response()]);
    [$status, $display] = route_prompt_run(['instance' => '12'], [
        's', 'h', 'o', 'p', '.', 't', 'e', 's', 't', Key::ENTER,
    ]);

    expect($status)->toBe(0)
        ->and($display)->toContain('Route domain')
        ->and($mock->getLastRequest()?->body()->all())->toBe([
            'domain' => 'shop.test',
            'publication' => 'private',
            'instance_id' => 12,
        ]);
    $mock->assertNotSent(ListInstancesRequest::class);
});

it('refuses an empty Instance selection without creating a Route', function (): void {
    $mock = route_prompt_mock([]);
    [$status, $display] = route_prompt_run(['domain' => 'shop.test'], []);

    expect($status)->toBe(1)
        ->and($display)->toContain('No matching records were found.');
    $mock->assertNotSent(CreateRouteRequest::class);
});

it('does not create a Route after cancelling Instance selection', function (): void {
    $mock = route_prompt_mock();
    [$status] = route_prompt_run(['domain' => 'shop.test'], [Key::CTRL_C]);

    expect($status)->not->toBe(0);
    $mock->assertNotSent(CreateRouteRequest::class);
});

/** @param list<array<string, mixed>>|null $instances */
function route_prompt_mock(?array $instances = null): MockClient
{
    $instances ??= [
        instance_payload(),
        [...instance_payload(), 'id' => 12, 'name' => 'shop', 'project' => ['id' => 4, 'name' => 'Store', 'slug' => 'store']],
    ];

    return MockClient::global([
        ListInstancesRequest::class => MockResponse::make([
            'data' => $instances,
            'meta' => ['request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
        ]),
        CreateRouteRequest::class => route_prompt_response(),
    ]);
}

function route_prompt_response(): MockResponse
{
    return MockResponse::make([
        'data' => [
            'id' => 11, 'kind' => 'app', 'project_id' => 3, 'node_id' => 2,
            'cluster_id' => null, 'generation_basis_node_id' => null,
            'domain' => 'shop.test', 'provenance' => 'explicit', 'publication' => 'private',
            'status' => 'pending', 'failed_step' => null, 'error_code' => null,
            'replaces_route_id' => null, 'replaced_by_route_id' => null,
            'replacement_step' => null, 'target_set_step' => null,
            'target' => ['id' => 13, 'instance_id' => 12, 'position' => 0],
            'targets' => [['id' => 13, 'instance_id' => 12, 'position' => 0]],
            'process_id' => null, 'upstream' => null,
        ],
        'meta' => ['request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
    ], 201);
}

/**
 * @param  array<string, string>  $arguments
 * @param  list<string>  $keys
 * @return array{int, string}
 */
function route_prompt_run(array $arguments, array $keys): array
{
    $command = new RoutePromptsCommand(new RoutePromptsTerminal($keys));
    $command->setLaravel(app());
    $tester = new CommandTester($command);

    return PromptContext::preserve(function () use ($tester, $arguments): array {
        Prompt::fallbackWhen(false);

        return [$tester->execute($arguments), $tester->getDisplay()];
    });
}

final class RoutePromptsCommand extends CreateRouteCommand
{
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
}

final class RoutePromptsTerminal extends Terminal
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
