<?php

declare(strict_types=1);

use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->configDirectory = sys_get_temp_dir().'/orbit-cli-security-'.Str::uuid();
    $this->configPath = $this->configDirectory.'/config.json';
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->configDirectory);
});

it('rejects malformed persisted json without exposing its contents', function (): void {
    write_gateway_security_config(
        directory: $this->configDirectory,
        path: $this->configPath,
        contents: '{"API_TOKEN":"persisted-secret"',
    );
    $repository = new GatewayConfigRepository($this->configPath);

    try {
        $repository->active();
        $this->fail('Expected the corrupted gateway configuration to be rejected.');
    } catch (GatewayConfigException $exception) {
        expect($exception->getMessage())
            ->toBe('Orbit gateway configuration is invalid.')
            ->not->toContain('persisted-secret');
    }
});

it('rejects corrupted persisted gateway profiles', function (array $config): void {
    write_gateway_security_config(
        directory: $this->configDirectory,
        path: $this->configPath,
        contents: json_encode($config, JSON_THROW_ON_ERROR),
    );
    $repository = new GatewayConfigRepository($this->configPath);

    expect($repository->active(...))
        ->toThrow(GatewayConfigException::class, 'Orbit gateway configuration is invalid.');
})->with([
    'missing gateways object' => [
        ['active_gateway' => null],
    ],
    'invalid active gateway type' => [[
        'active_gateway' => 7,
        'gateways' => (object) [],
    ]],
    'missing active profile' => [[
        'active_gateway' => 'missing',
        'gateways' => (object) [],
    ]],
    'invalid profile name' => [[
        'active_gateway' => "test\npersisted-secret",
        'gateways' => [
            "test\npersisted-secret" => ['url' => 'https://10.70.0.1', 'ca_path' => null],
        ],
    ]],
    'credential-shaped profile name' => [[
        'active_gateway' => 'API_TOKEN=profile-secret',
        'gateways' => [
            'API_TOKEN=profile-secret' => ['url' => 'https://10.70.0.1', 'ca_path' => null],
        ],
    ]],
    'non-object profile' => [[
        'active_gateway' => 'test',
        'gateways' => ['test' => 'persisted-secret'],
    ]],
    'missing URL' => [[
        'active_gateway' => 'test',
        'gateways' => ['test' => ['ca_path' => null]],
    ]],
    'plain HTTP URL' => [[
        'active_gateway' => 'test',
        'gateways' => ['test' => ['url' => 'http://10.70.0.1', 'ca_path' => null]],
    ]],
    'credential-bearing URL' => [[
        'active_gateway' => 'test',
        'gateways' => ['test' => ['url' => 'https://user:persisted-secret@10.70.0.1', 'ca_path' => null]],
    ]],
    'URL base path' => [[
        'active_gateway' => 'test',
        'gateways' => ['test' => ['url' => 'https://10.70.0.1/base', 'ca_path' => null]],
    ]],
    'URL query' => [[
        'active_gateway' => 'test',
        'gateways' => ['test' => ['url' => 'https://10.70.0.1?token=persisted-secret', 'ca_path' => null]],
    ]],
    'URL fragment' => [[
        'active_gateway' => 'test',
        'gateways' => ['test' => ['url' => 'https://10.70.0.1#persisted-secret', 'ca_path' => null]],
    ]],
    'relative CA path' => [[
        'active_gateway' => 'test',
        'gateways' => ['test' => ['url' => 'https://10.70.0.1', 'ca_path' => 'relative/root.pem']],
    ]],
    'control character in CA path' => [[
        'active_gateway' => 'test',
        'gateways' => ['test' => ['url' => 'https://10.70.0.1', 'ca_path' => "/tmp/root.pem\npersisted-secret"]],
    ]],
]);

it('rejects a gateway configuration readable by other users', function (): void {
    write_gateway_security_config(
        directory: $this->configDirectory,
        path: $this->configPath,
        contents: json_encode([
            'active_gateway' => 'test',
            'gateways' => [
                'test' => ['url' => 'https://10.70.0.1', 'ca_path' => null],
            ],
        ], JSON_THROW_ON_ERROR),
        permissions: 0o644,
    );
    $repository = new GatewayConfigRepository($this->configPath);

    expect($repository->active(...))
        ->toThrow(GatewayConfigException::class, 'Orbit gateway configuration is not private.');
});

function write_gateway_security_config(
    string $directory,
    string $path,
    string $contents,
    int $permissions = 0o600,
): void {
    mkdir(directory: $directory, permissions: 0o700, recursive: true);
    file_put_contents(filename: $path, data: $contents);
    chmod(filename: $path, permissions: $permissions);
}
