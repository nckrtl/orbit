<?php

declare(strict_types=1);

use App\Actions\Instances\ImportInstanceEnvironmentAction;
use App\Domain\Instances\Environment\InstanceEnvironmentImporter;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Encryption\Encrypter;

it('stores a usable Laravel key before sync when importing an empty APP_KEY', function (bool $production, bool $existingFile, ?string $stored): void {
    $instance = empty_app_key_import_fixture($production, "APP_KEY=\nAPP_ENV=local\n");
    if ($stored !== null) {
        $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => $stored]);
    }

    $action = app(ImportInstanceEnvironmentAction::class);
    $result = $existingFile ? $action->importExisting($instance) : $action->execute($instance, replace: true);

    $key = $instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value;
    expect($key)->toStartWith('base64:');
    $decoded = base64_decode(substr($key, 7), strict: true);
    expect($decoded)->toBeString();
    expect(strlen($decoded))->toBe(32);
    $encrypter = new Encrypter($decoded, 'AES-256-CBC');
    expect($encrypter->decryptString($encrypter->encryptString('usable')))->toBe('usable');
    expect($result?->changed)->toBeTrue();
})->with([
    'e2e-dev import' => [false, false, null],
    'e2e-prod import' => [true, false, null],
    'create existing file' => [false, true, null],
    'repair stored empty key' => [true, false, ''],
]);

it('does not rotate a stored key when replacing an empty APP_KEY', function (): void {
    $instance = empty_app_key_import_fixture(false, "APP_KEY=\n");
    $stored = 'base64:'.base64_encode(str_repeat('k', 32));
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => $stored]);

    app(ImportInstanceEnvironmentAction::class)->execute($instance, replace: true);
    $result = app(ImportInstanceEnvironmentAction::class)->execute($instance, replace: true);

    expect($instance->environmentValues()->where('env_key', 'APP_KEY')->sole()->env_value)->toBe($stored);
    expect($result->changed)->toBeFalse();
});

it('keeps normal import conflict semantics for an empty APP_KEY', function (): void {
    $instance = empty_app_key_import_fixture(false, "APP_KEY=\n");
    $instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'original']);

    expect(fn () => app(ImportInstanceEnvironmentAction::class)->execute($instance, replace: false))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.import_conflict');
        });
    expect($instance->environmentValues()->sole()->env_value)->toBe('original');
});

it('preserves non-empty Laravel keys and does not initialize absent or non-Laravel keys', function (bool $laravel, string $contents, ?string $expected): void {
    $instance = empty_app_key_import_fixture(false, $contents);
    $instance->update(['source_is_laravel' => $laravel]);

    app(ImportInstanceEnvironmentAction::class)->execute($instance, replace: false);

    expect($instance->environmentValues()->where('env_key', 'APP_KEY')->first()?->env_value)->toBe($expected);
})->with([
    'non-empty Laravel key' => [true, "APP_KEY=original\n", 'original'],
    'absent Laravel key' => [true, "OTHER=value\n", null],
    'empty non-Laravel key' => [false, "APP_KEY=\n", ''],
]);

function empty_app_key_import_fixture(bool $production, string $contents): Instance
{
    $node = Node::query()->create([
        'name' => 'empty-key-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.201',
        'wireguard_ip' => '10.44.0.201',
        'user' => 'orbit',
    ]);
    $node->roles()->create([
        'role' => $production ? RoleName::AppProd : RoleName::AppDev,
        'status' => LifecycleStatus::Active,
    ]);
    $project = Project::query()->create([
        'name' => 'Empty key',
        'slug' => 'empty-key',
        'repository_url' => 'https://example.test/empty-key.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $production ? 'e2e-prod' : 'e2e-dev',
        'environment' => $production ? 'production' : 'development',
        'checkout_path' => $production ? '/home/empty-key/releases/initial' : '/srv/orbit/empty-key/default',
        'production_home' => $production ? '/home/empty-key' : null,
        'production_user' => $production ? 'empty-key' : null,
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'empty-key.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => 'active']);
    $reader = Mockery::mock(InstanceEnvironmentReader::class);
    $reader->shouldReceive('read')->andReturn($contents);
    $preflight = Mockery::mock(InstanceOperationPreflight::class);
    $preflight->shouldReceive('assertEnvironmentReadable');
    app()->instance(InstanceEnvironmentReader::class, $reader);
    app()->instance(InstanceOperationPreflight::class, $preflight);

    return $instance;
}

it('parses dotenv syntax and expands only preceding file-local values', function (): void {
    putenv('GATEWAY_FALLBACK=must-not-be-read');
    $_ENV['GATEWAY_FALLBACK'] = 'must-not-be-read';
    $contents = <<<'DOTENV'
        # comment
        BASE=alpha
        PLAIN=${BASE}-plain
        DOUBLE="line one\n${BASE}"
        SINGLE='literal ${BASE}'
        ESCAPED="quote: \""
        MULTILINE="first
        second"
        UNICODE=é
        UNICODE_EXPANDED="é${UNICODE}"

        DOTENV;

    $values = app(InstanceEnvironmentImporter::class)->parse($contents);

    expect($values)->toBe([
        'BASE' => 'alpha',
        'PLAIN' => 'alpha-plain',
        'DOUBLE' => "line one\nalpha",
        'SINGLE' => 'literal ${BASE}',
        'ESCAPED' => 'quote: "',
        'MULTILINE' => "first\nsecond",
        'UNICODE' => 'é',
        'UNICODE_EXPANDED' => 'éé',
    ]);
});

it('rejects duplicate unresolved invalid and oversized dotenv input atomically', function (string $contents): void {
    expect(fn () => app(InstanceEnvironmentImporter::class)->parse($contents))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('env.import_invalid');
        });
})->with([
    'duplicate' => ["KEY=one\nKEY=two\n"],
    'unresolved local expansion' => ['KEY=${MISSING}'],
    'gateway environment fallback' => ['KEY=${GATEWAY_FALLBACK}'],
    'invalid syntax' => ['KEY value'],
    'numeric key' => ['123=value'],
    'oversized' => [str_repeat('x', 1_048_577)],
]);
