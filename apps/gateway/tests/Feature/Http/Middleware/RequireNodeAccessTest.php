<?php

declare(strict_types=1);

use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolStatus;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Models\App as OrbitApp;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Tool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Route::middleware([
        RequireActiveWireGuardPeer::class,
        SubstituteBindings::class,
        CaptureNodeAccessErrorCode::class,
        RequireNodeAccess::class,
    ])->prefix('_node-access')->group(function (): void {
        Route::get('target/{node}', [NodeAccessTestController::class, 'target']);
        Route::get('override/{node}', [NodeAccessTestController::class, 'override']);
        Route::get('app/{app}', [NodeAccessTestController::class, 'app']);
        Route::get('collection', [NodeAccessTestController::class, 'collection']);
        Route::get('gateway', [NodeAccessTestController::class, 'gateway']);
        Route::post('instance', [NodeAccessTestController::class, 'instance']);
        Route::get('process', [NodeAccessTestController::class, 'process']);
        Route::get('tool/{tool}', [NodeAccessTestController::class, 'tool']);
        Route::post('tool-raw', [NodeAccessTestController::class, 'toolRaw']);
        Route::get('missing-scope', [NodeAccessMissingScopeController::class, 'show']);
    });

    NodeAccessMissingScopeController::$executed = false;
    NodeAccessTestController::$gatewayExecuted = false;
});

it('allows direct access to a Tool-owning node', function (): void {
    $consumer = middleware_node('consumer');
    $target = middleware_tool('target');
    $consumer->accessibleNodes()->attach($target->node);
    middleware_gateway();

    middleware_get($this, $consumer, "/_node-access/tool/{$target->id}")
        ->assertOk()
        ->assertJsonPath('node_id', $target->node_id);
});

it('allows Gateway fleet authority for Tool ownership without an edge', function (): void {
    $gateway = middleware_gateway();
    $tool = middleware_tool('target');

    middleware_get($this, $gateway, "/_node-access/tool/{$tool->id}")->assertOk();
});

it('denies unrelated peers with the concrete Tool-owning node', function (): void {
    $consumer = middleware_node('consumer');
    $tool = middleware_tool('target');
    middleware_gateway();

    middleware_get($this, $consumer, "/_node-access/tool/{$tool->id}")
        ->assertForbidden()
        ->assertJsonPath('error.details.serving_node.id', $tool->node_id);
});

it('uses Tool.node_id when its manager belongs to another node', function (): void {
    $consumer = middleware_node('consumer');
    $toolNode = middleware_node('tool-node');
    $managerNode = middleware_node('manager-node');
    $tool = middleware_tool('owned', $toolNode, $managerNode);
    $consumer->accessibleNodes()->attach($toolNode);
    middleware_gateway();

    middleware_get($this, $consumer, "/_node-access/tool/{$tool->id}")
        ->assertOk()
        ->assertJsonPath('node_id', $toolNode->id);
});

it('resolves raw Tool node access and leaves invalid input to validation', function (
    array $input,
    string $assertion,
): void {
    $consumer = middleware_node('consumer');
    middleware_gateway();

    middleware_post($this, $consumer, '/_node-access/tool-raw', $input)->{$assertion}();
})->with([
    'missing' => [[], 'assertUnprocessable'],
    'malformed' => [['node_id' => 'bad'], 'assertUnprocessable'],
    'valid missing node returns 404' => [['node_id' => 999999], 'assertNotFound'],
    'zero node returns 422' => [['node_id' => 0], 'assertUnprocessable'],
    'negative node returns 422' => [['node_id' => -1], 'assertUnprocessable'],
]);

it('returns 404 when a bound Tool is missing', function (): void {
    $consumer = middleware_node('consumer');
    middleware_gateway();

    middleware_get($this, $consumer, '/_node-access/tool/999999')->assertNotFound();
});

it('uses a method access declaration before the class declaration', function (): void {
    $consumer = middleware_node('consumer');
    $target = middleware_node('target');
    $consumer->accessibleNodes()->attach($target);
    middleware_gateway();

    middleware_get($this, $consumer, "/_node-access/override/{$target->id}")
        ->assertOk()
        ->assertJsonPath('node_id', $target->id);
});

it('allows the active Gateway peer without an access row', function (): void {
    $gateway = middleware_gateway();
    $target = middleware_node('target');

    middleware_get($this, $gateway, "/_node-access/target/{$target->id}")->assertOk();
});

it('fails closed for a Gateway scope without an active Gateway', function (): void {
    $consumer = middleware_node('consumer');

    middleware_get(test: $this, consumer: $consumer, uri: '/_node-access/gateway')
        ->assertForbidden()
        ->assertHeader('X-Test-Orbit-Error-Code', 'node_access.required')
        ->assertJson([
            'error' => [
                'code' => 'node_access.required',
                'message' => 'Node access is required.',
                'details' => [
                    'consumer_node' => ['id' => $consumer->id, 'name' => $consumer->name],
                    'serving_node' => null,
                ],
            ],
        ]);

    expect(NodeAccessTestController::$gatewayExecuted)->toBeFalse();
});

it('fails closed for an unplaced app without an active Gateway', function (): void {
    $consumer = middleware_node('consumer');
    $app = middleware_app('unplaced-without-gateway');

    middleware_get($this, $consumer, "/_node-access/app/{$app->id}")
        ->assertForbidden()
        ->assertHeader('X-Test-Orbit-Error-Code', 'node_access.required')
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.consumer_node.id', $consumer->id)
        ->assertJsonPath('error.details.serving_node', null);
});

it('identifies the concrete Gateway in a normal Gateway-scope denial', function (): void {
    $consumer = middleware_node('consumer');
    $gateway = middleware_gateway();

    middleware_get(test: $this, consumer: $consumer, uri: '/_node-access/gateway')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.serving_node.id', $gateway->id);

    expect(NodeAccessTestController::$gatewayExecuted)->toBeFalse();
});

it('allows a peer with Gateway fleet authority', function (): void {
    $consumer = middleware_node('consumer');
    $gateway = middleware_gateway();
    $target = middleware_node('target');
    $consumer->accessibleNodes()->attach($gateway);

    middleware_get($this, $consumer, "/_node-access/target/{$target->id}")->assertOk();
});

it('allows a peer with exact direct access', function (): void {
    $consumer = middleware_node('consumer');
    $target = middleware_node('target');
    $consumer->accessibleNodes()->attach($target);
    middleware_gateway();

    middleware_get($this, $consumer, "/_node-access/target/{$target->id}")->assertOk();
});

it('allows access to any one node that owns a multiply placed app', function (): void {
    $consumer = middleware_node('consumer');
    $first = middleware_node('first');
    $second = middleware_node('second');
    $app = middleware_app('placed');
    middleware_instance($app, $first, name: 'first');
    middleware_instance($app, $second, name: 'second');
    $consumer->accessibleNodes()->attach($second);
    middleware_gateway();

    middleware_get($this, $consumer, "/_node-access/app/{$app->id}")->assertOk();
});

it('returns the exact denial for a concrete serving node', function (): void {
    $consumer = middleware_node('consumer');
    $target = middleware_node('target');
    middleware_gateway();

    middleware_get($this, $consumer, "/_node-access/target/{$target->id}")
        ->assertForbidden()
        ->assertHeader('X-Test-Orbit-Error-Code', 'node_access.required')
        ->assertJson([
            'error' => [
                'code' => 'node_access.required',
                'message' => 'Node access is required.',
                'details' => [
                    'consumer_node' => ['id' => $consumer->id, 'name' => $consumer->name],
                    'serving_node' => ['id' => $target->id, 'name' => $target->name],
                ],
            ],
        ]);
});

it('uses the first stable app node in a multiple-placement denial', function (): void {
    $consumer = middleware_node('consumer');
    $first = middleware_node('first');
    $second = middleware_node('second');
    $app = middleware_app('placed');
    middleware_instance($app, $second, name: 'second');
    middleware_instance($app, $first, name: 'first');
    middleware_gateway();

    middleware_get($this, $consumer, "/_node-access/app/{$app->id}")
        ->assertForbidden()
        ->assertJsonPath('error.details.serving_node.id', $first->id);
});

it('returns a null serving node for a denied collection', function (): void {
    $consumer = middleware_node('consumer');
    middleware_gateway();

    middleware_get(test: $this, consumer: $consumer, uri: '/_node-access/collection')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required')
        ->assertJsonPath('error.details.consumer_node.id', $consumer->id)
        ->assertJsonPath('error.details.serving_node', null);
});

it('fails closed when a protected action has no access declaration', function (): void {
    $consumer = middleware_node('consumer');
    middleware_gateway();

    middleware_get(test: $this, consumer: $consumer, uri: '/_node-access/missing-scope')
        ->assertInternalServerError()
        ->assertHeader('X-Test-Orbit-Error-Code', 'node_access.scope_missing')
        ->assertJson([
            'error' => [
                'code' => 'node_access.scope_missing',
                'message' => 'Node access scope is missing.',
                'details' => [],
            ],
        ]);

    expect(NodeAccessMissingScopeController::$executed)->toBeFalse();
});

it('returns the normal API 404 for a missing bound resource before access', function (): void {
    $consumer = middleware_node('consumer');
    middleware_gateway();

    middleware_get(test: $this, consumer: $consumer, uri: '/_node-access/target/999999')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');
});

it('returns the normal API 404 for a valid missing raw resource identifier', function (): void {
    $consumer = middleware_node('consumer');
    middleware_gateway();

    middleware_post(
        test: $this,
        consumer: $consumer,
        uri: '/_node-access/instance',
        input: ['node_id' => 999_999],
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');
});

it('returns the normal API 404 for a valid missing raw process target', function (): void {
    $consumer = middleware_node('consumer');
    middleware_gateway();

    middleware_get(
        test: $this,
        consumer: $consumer,
        uri: '/_node-access/process?target_type=instance&target_id=999999',
    )
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');
});

it('leaves malformed or absent raw input to validation', function (array $input): void {
    $consumer = middleware_node('consumer');
    middleware_gateway();

    middleware_post(test: $this, consumer: $consumer, uri: '/_node-access/instance', input: $input)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');
})->with([
    'absent input' => [[]],
    'malformed input' => [['node_id' => 'not-a-number']],
]);

it('leaves malformed or absent raw process input to validation', function (string $query): void {
    $consumer = middleware_node('consumer');
    middleware_gateway();

    middleware_get($this, $consumer, '/_node-access/process'.$query)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');
})->with([
    'absent input' => [''],
    'malformed type' => ['?target_type=node&target_id=1'],
    'malformed id' => ['?target_type=instance&target_id=not-a-number'],
]);

function middleware_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => $name.'.example.test',
        'wireguard_address' => '10.44.0.'.(Node::query()->count() + 2),
    ]);
}

function middleware_gateway(): Node
{
    $gateway = middleware_node('gateway');
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);

    return $gateway;
}

function middleware_app(string $slug): OrbitApp
{
    return OrbitApp::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'repository_url' => 'https://example.test/'.$slug.'.git',
    ]);
}

function middleware_instance(OrbitApp $app, Node $node, string $name): Instance
{
    return Instance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'testing',
        'checkout_path' => '/srv/'.$name,
        'hostname' => $name.'.example.test',
        'certificate_mode' => CertificateMode::OrbitCa,
        'status' => LifecycleStatus::Active,
    ]);
}

function middleware_tool(string $package, ?Node $toolNode = null, ?Node $managerNode = null): Tool
{
    $toolNode ??= middleware_node($package.'-node');
    $managerNode ??= $toolNode;
    $manager = $managerNode
        ->toolManagers()
        ->create(['name' => ToolManagerName::Apt, 'status' => LifecycleStatus::Active]);

    return $toolNode
        ->tools()
        ->create([
            'tool_manager_id' => $manager->id,
            'package' => $package,
            'status' => ToolStatus::Installed,
            'installed_version' => '1.0.0',
        ]);
}

function middleware_get(Tests\TestCase $test, Node $consumer, string $uri): Illuminate\Testing\TestResponse
{
    return $test
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_address])
        ->getJson($uri);
}

/** @param array<string, mixed> $input */
function middleware_post(
    Tests\TestCase $test,
    Node $consumer,
    string $uri,
    array $input,
): Illuminate\Testing\TestResponse {
    return $test
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_address])
        ->postJson($uri, $input);
}

#[RequiresNodeAccess(ServingNode::Gateway)]
final class NodeAccessTestController
{
    public static bool $gatewayExecuted = false;

    /** @return array{ok: true} */
    public function gateway(): array
    {
        self::$gatewayExecuted = true;

        return ['ok' => true];
    }

    /** @return array{node_id: int} */
    #[RequiresNodeAccess(ServingNode::Target)]
    public function override(Node $node): array
    {
        return ['node_id' => $node->id];
    }

    /** @return array{node_id: int} */
    #[RequiresNodeAccess(ServingNode::Target)]
    public function target(Node $node): array
    {
        return ['node_id' => $node->id];
    }

    /** @return array{app_id: int} */
    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function app(OrbitApp $app): array
    {
        return ['app_id' => $app->id];
    }

    /** @return array{ok: true} */
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function collection(): array
    {
        return ['ok' => true];
    }

    /** @return array{node_id: int} */
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function instance(NodeAccessInstanceRequest $request): array
    {
        return ['node_id' => (int) $request->validated('node_id')];
    }

    /** @return array{target_id: int} */
    #[RequiresNodeAccess(ServingNode::ProcessOwning)]
    public function process(NodeAccessProcessRequest $request): array
    {
        return ['target_id' => (int) $request->validated('target_id')];
    }

    #[RequiresNodeAccess(ServingNode::ToolOwning)]
    public function tool(Tool $tool): array
    {
        return ['node_id' => $tool->node_id];
    }

    #[RequiresNodeAccess(ServingNode::ToolOwning)]
    public function toolRaw(NodeAccessToolRequest $request): array
    {
        return ['node_id' => (int) $request->validated('node_id')];
    }
}

/** @mago-expect lint:single-class-per-file Test-only controller fixture. */
final class NodeAccessMissingScopeController
{
    public static bool $executed = false;

    /** @return array{ok: true} */
    public function show(): array
    {
        self::$executed = true;

        return ['ok' => true];
    }
}

/** @mago-expect lint:single-class-per-file Test-only request fixture. */
final class NodeAccessInstanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['node_id' => ['required', 'integer', 'exists:nodes,id']];
    }
}

/** @mago-expect lint:single-class-per-file Test-only request fixture. */
final class NodeAccessProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'target_type' => ['required', 'in:instance,workspace'],
            'target_id' => ['required', 'integer', 'min:1'],
        ];
    }
}

/** @mago-expect lint:single-class-per-file Test-only request fixture. */
final class NodeAccessToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['node_id' => ['required', 'integer', 'min:1', 'exists:nodes,id']];
    }
}

/** @mago-expect lint:single-class-per-file Test-only middleware fixture. */
final class CaptureNodeAccessErrorCode
{
    /** @param \Closure(Request): Response $next */
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);
        $errorCode = $request->attributes->get('orbit.error_code');

        if (is_string($errorCode)) {
            $response->headers->set('X-Test-Orbit-Error-Code', $errorCode);
        }

        return $response;
    }
}
