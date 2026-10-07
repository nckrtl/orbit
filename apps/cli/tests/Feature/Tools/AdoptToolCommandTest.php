<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\AdoptToolRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tool-adopt-'.Str::uuid();
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
    if ($this->previousColumns === false) {
        putenv('COLUMNS');
    } else {
        putenv('COLUMNS='.$this->previousColumns);
    }
});

it('sends the exact package identity and does not decide ownership itself', function (): void {
    $mock = MockClient::global(gateway_fixture_mock('tools/tool-adopt/adopted'));

    expect(Artisan::call('tool:adopt', [
        'package' => 'wireguard-tools',
        '--node' => '2',
        '--manager' => 'unlisted',
        '--constraint' => '^1.2',
        '--yes' => true,
        '--json' => true,
    ]))->toBe(0);

    $pending = $mock->getLastPendingRequest();
    expect($mock->getLastRequest())
        ->toBeInstanceOf(AdoptToolRequest::class)
        ->and($pending?->getMethod())
        ->toBe(Method::POST)
        ->and($pending?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools/adopt')
        ->and($pending?->query()->all())
        ->toBe([])
        ->and($pending?->body()->all())
        ->toBe([
            'node_id' => 2,
            'manager' => 'unlisted',
            'package' => 'wireguard-tools',
            'version_constraint' => '^1.2',
        ]);
});

it('omits a missing constraint instead of inventing one', function (): void {
    $mock = MockClient::global(gateway_fixture_mock('tools/tool-adopt/adopted'));

    expect(Artisan::call('tool:adopt', [
        'package' => 'jq',
        '--node' => '2',
        '--manager' => 'apt',
        '--yes' => true,
        '--json' => true,
    ]))->toBe(0);
    expect($mock->getLastPendingRequest()?->body()->all())
        ->toBe([
            'node_id' => 2,
            'manager' => 'apt',
            'package' => 'jq',
        ]);
});

it('requires consent before any adoption request when it cannot prompt', function (array $arguments): void {
    $mock = MockClient::global(gateway_fixture_mock('tools/tool-adopt/adopted'));

    expect(Artisan::call('tool:adopt', $arguments))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('Supply --yes to confirm this operation.');
    if (($arguments['--json'] ?? false) === true) {
        expect($output)->toContain('input.confirmation_required');
    }
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'human' => [['package' => 'jq', '--node' => '2', '--manager' => 'apt']],
    'json' => [['package' => 'jq', '--node' => '2', '--manager' => 'apt', '--json' => true]],
]);

it('rejects invalid identity before consent or a request', function (array $arguments, string $code): void {
    $mock = MockClient::global(gateway_fixture_mock('tools/tool-adopt/adopted'));

    expect(Artisan::call('tool:adopt', [...$arguments, '--yes' => true, '--json' => true]))->toBe(1);
    expect(Artisan::output())->toContain($code);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'node' => [['package' => 'jq', '--node' => '0', '--manager' => 'apt'], 'tool.node_id_invalid'],
    'manager' => [['package' => 'jq', '--node' => '2'], 'tool.manager_required'],
    'package' => [['package' => "jq\nname", '--node' => '2', '--manager' => 'apt'], 'tool.package_invalid'],
    'oversized package' => [['package' => str_repeat('x', 256), '--node' => '2', '--manager' => 'apt'], 'tool.package_invalid'],
]);

it('asks for default-No ownership consent and cancels before the adoption request', function (array $keys, bool $accepted): void {
    $result = run_tool_adopt_consent(
        ['tool:adopt', 'jq', '--node=2', '--manager=apt', '--no-ansi'],
        $keys,
        $accepted ? [tool_adopt_consent_reply()] : [],
    );

    expect($result['status'])->toBe($accepted ? 0 : 1)
        ->and($result['prompt_seen'])->toBeTrue()
        ->and($result['keys_remaining'])->toBe(0)
        ->and($result['restored'])->toBeTrue()
        ->and($result['output'])->toContain('Take ownership of package [jq] (apt on Node #2) and manage later updates and removal?');
    expect($result['requests'])->toHaveCount($accepted ? 1 : 0);
    if ($accepted) {
        expect($result['requests'][0]['class'])->toBe(AdoptToolRequest::class)
            ->and($result['requests'][0]['body'])->toBe([
                'node_id' => 2,
                'manager' => 'apt',
                'package' => 'jq',
            ]);
    } else {
        expect($result['output'])->toContain('Tool adoption cancelled.');
    }
})->with([
    'default No' => [["\r"], false],
    'Yes' => [['y', "\r"], true],
    'Ctrl-C' => [["\x03"], false],
    'EOF' => [["\x04"], false],
]);

it('includes the supplied constraint in the consent question and the request', function (): void {
    $result = run_tool_adopt_consent(
        ['tool:adopt', 'jq', '--node=2', '--manager=brew-cask', '--constraint=^4.0', '--no-ansi'],
        ['y', "\r"],
        [tool_adopt_consent_reply('brew-cask', '^4.0')],
    );

    expect($result['status'])->toBe(0)
        ->and($result['requests'])->toHaveCount(1)
        ->and($result['requests'][0]['body'])->toBe([
            'node_id' => 2,
            'manager' => 'brew-cask',
            'package' => 'jq',
            'version_constraint' => '^4.0',
        ])
        ->and($result['output'])->toContain('constraint ^4.0');
});

it('does not prompt for consent when --yes is explicit', function (bool $json): void {
    $arguments = ['tool:adopt', 'jq', '--node=2', '--manager=apt', '--yes', '--no-ansi'];
    if ($json) {
        $arguments[] = '--json';
    }
    $result = run_tool_adopt_consent($arguments, [], [tool_adopt_consent_reply()]);

    expect($result['status'])->toBe(0)
        ->and($result['prompt_seen'])->toBeFalse()
        ->and($result['requests'])->toHaveCount(1)
        ->and($result['requests'][0]['class'])->toBe(AdoptToolRequest::class);
})->with([
    'human' => [false],
    'json' => [true],
]);

it('refuses JSON consent from a terminal without sending the adoption request', function (): void {
    $result = run_tool_adopt_consent(
        ['tool:adopt', 'jq', '--node=2', '--manager=apt', '--json'],
        [],
        [],
    );

    expect($result['status'])->toBe(1)
        ->and($result['prompt_seen'])->toBeFalse()
        ->and($result['requests'])->toBe([])
        ->and($result['output'])->toContain('input.confirmation_required');
});

/**
 * @param  list<string>  $keys
 * @param  list<array<string, mixed>>  $replies
 * @return array<string, mixed>
 */
function run_tool_adopt_consent(array $arguments, array $keys, array $replies, bool $pty = true): array
{
    $directory = sys_get_temp_dir().'/orbit-tool-adopt-consent-'.Str::uuid();
    new Filesystem()->makeDirectory($directory);
    $configuration = [
        'home' => $directory.'/home',
        'trace' => $directory.'/requests.jsonl',
        'keys' => array_map(base64_encode(...), $keys),
        'arguments' => $arguments,
        'replies' => $replies,
        'prompt' => 'Take ownership of package',
        'columns' => 200,
    ];
    file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    $fixtures = dirname(__DIR__, 2).'/Fixtures/Console/';
    $argv = [PHP_BINARY, $fixtures.'command-consent.php', $directory.'/case.json'];

    try {
        $process = new Process($pty ? ['python3', $fixtures.'command-consent-pty.py', ...$argv] : $argv, env: ['PAO_DISABLE' => '1']);
        $process->setTimeout(15);
        $process->run();
        if ($pty) {
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $result['output'] = base64_decode((string) $result['raw']);
        } else {
            $result = ['status' => $process->getExitCode(), 'output' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
        }
        $result['requests'] = is_file($configuration['trace']) ? array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($configuration['trace'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        ) : [];

        return $result;
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
}

/** @return array<string, mixed> */
function tool_adopt_consent_reply(string $manager = 'apt', ?string $constraint = null): array
{
    return [
        'class' => AdoptToolRequest::class,
        'status' => 201,
        'body' => [
            'data' => [
                'id' => 1,
                'node_id' => 2,
                'manager' => $manager,
                'package' => 'jq',
                'version_constraint' => $constraint,
                'status' => 'installed',
                'installed_version' => '1.2.3',
                'failed_operation' => null,
                'error_code' => null,
                'outcome' => 'applied',
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ],
    ];
}
