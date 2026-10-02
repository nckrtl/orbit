<?php

declare(strict_types=1);

use App\Http\Requests\DatabaseConnections\UpdateDatabaseConnectionRequest;
use App\Http\Requests\Instances\StoreInstanceDeployStepRequest;
use App\Http\Requests\Instances\UpdateInstanceDeployStepRequest;
use App\Http\Requests\Instances\UpdateInstanceRequest;
use App\Http\Requests\Nodes\UpdateNodeSettingsRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;

it('publishes validated write body fields in OpenAPI and MCP', function (string $operationId, string $requestClass, array $types, array $required): void {
    $root = dirname(__DIR__, 4);
    $openapi = json_decode((string) file_get_contents($root.'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
    $catalogue = json_decode((string) file_get_contents($root.'/apps/gateway/resources/mcp/tools.json'), true, flags: JSON_THROW_ON_ERROR);
    $operations = collect($openapi['paths'])->flatMap(fn (array $path): array => array_values($path))->keyBy('operationId');
    $tools = collect($catalogue['tools'])->keyBy('name');

    $operation = $operations->get($operationId);
    $body = $operation['requestBody']['content']['application/json']['schema'];
    $input = $tools->get($operationId)['input_schema'];

    expect($body['additionalProperties'])->toBeFalse();
    expect(array_keys($body['properties'] ?? []))->toEqualCanonicalizing(array_keys($types));
    expect($body['required'] ?? [])->toEqualCanonicalizing($required);
    expect($input['additionalProperties'])->toBeFalse();

    $ruleFields = array_map(fn (string $key): string => explode('.', $key)[0], array_keys(new $requestClass()->rules()));
    expect(array_unique($ruleFields))->toEqualCanonicalizing(array_keys($types));

    foreach ($types as $field => $type) {
        expect($body['properties'][$field]['type'])->toBe($type);
        expect($input['properties'][$field])->toBe($body['properties'][$field]);
    }

    $pathFields = array_column($operation['parameters'], 'name');
    $pathFields = array_map(fn (string $field): string => $operationId === 'instance-deploy-step-update' && $field === 'name' ? 'step' : $field, $pathFields);
    expect(array_keys($input['properties']))->toEqualCanonicalizing([...$pathFields, ...array_keys($types)]);
    expect($input['required'] ?? [])->toEqualCanonicalizing([...$pathFields, ...$required]);

    if (isset($types['phase'])) {
        expect($body['properties']['phase']['enum'])->toBe(['before_activation', 'after_activation']);
    }

    if ($operationId === 'node-settings') {
        expect($body['properties']['apps']['additionalProperties'])->toBeFalse();
        expect($body['properties']['apps']['properties']['path']['type'])->toBe(['string', 'null']);
        expect(array_keys($body['properties']['apps']['properties']))->toBe(['path']);
        expect($body['properties']['apps']['required'] ?? [])->toBe([]);
        expect($body['properties']['apps']['minProperties'] ?? 0)->toBe(0);
    }

    if ($operationId === 'database-update') {
        expect($body['properties']['driver']['enum'])->toBe(['mysql', 'pgsql', 'sqlite', 'redis']);
        expect($body['properties']['port']['minimum'])->toBe(1);
        expect($body['properties']['port']['maximum'])->toBe(65535);
    }

    if ($required !== []) {
        expect($operation['requestBody']['required'])->toBeTrue();
    }

    if ($operationId === 'instance-deploy-step-create') {
        expect($body['properties']['timeout_seconds']['minimum'])->toBe(1);
        expect($body['properties']['timeout_seconds']['maximum'])->toBe(900);
    }
})->with([
    'instance branch' => ['instance-update', UpdateInstanceRequest::class, ['branch' => 'string'], ['branch']],
    'create deploy step' => ['instance-deploy-step-create', StoreInstanceDeployStepRequest::class, [
        'name' => 'string', 'command' => 'string', 'phase' => 'string', 'timeout_seconds' => 'integer', 'before' => 'string', 'after' => 'string',
    ], ['name', 'command']],
    'update deploy step' => ['instance-deploy-step-update', UpdateInstanceDeployStepRequest::class, [
        'command' => 'string', 'phase' => 'string', 'timeout_seconds' => 'integer', 'before' => 'string', 'after' => 'string',
    ], []],
    'update database connection' => ['database-update', UpdateDatabaseConnectionRequest::class, [
        'driver' => 'string', 'node_id' => ['integer', 'null'], 'host' => ['string', 'null'], 'port' => ['integer', 'null'],
        'database' => ['string', 'null'], 'path' => ['string', 'null'], 'username' => ['string', 'null'], 'password' => ['string', 'null'],
    ], []],
    'node storage settings' => ['node-settings', UpdateNodeSettingsRequest::class, ['apps' => ['object', 'null']], ['apps']],
]);

it('publishes database patterns that enforce the runtime field constraints', function (string $field, string $value, bool $valid): void {
    $root = dirname(__DIR__, 4);
    $openapi = json_decode((string) file_get_contents($root.'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
    $catalogue = json_decode((string) file_get_contents($root.'/apps/gateway/resources/mcp/tools.json'), true, flags: JSON_THROW_ON_ERROR);
    $operations = collect($openapi['paths'])->flatMap(fn (array $path): array => array_values($path))->keyBy('operationId');
    $tools = collect($catalogue['tools'])->keyBy('name');
    $body = $operations->get('database-update')['requestBody']['content']['application/json']['schema'];
    $input = $tools->get('database-update')['input_schema'];
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));

    expect($factory->make([$field => $value], new UpdateDatabaseConnectionRequest()->rules())->passes())->toBe($valid);

    foreach ([$body['properties'][$field], $input['properties'][$field]] as $schema) {
        expect($schema['pattern'])->not->toBeEmpty();
        expect($schema['pattern'])->toStartWith('^')->toEndWith('$(?![\\s\\S])');
        expect(preg_match('~'.$schema['pattern'].'~', $value))->toBe($valid ? 1 : 0);
    }
})->with([
    'host' => ['host', 'db.example.test', true],
    'invalid host' => ['host', '@bad', false],
    'host with trailing newline' => ['host', "db.example.test\n", false],
    'database' => ['database', 'orbit_db', true],
    'Redis database index' => ['database', '12', true],
    'invalid database' => ['database', 'bad/name', false],
    'database with trailing newline' => ['database', "orbit_db\n", false],
    'absolute path' => ['path', '/srv/orbit/database.sqlite', true],
    'relative path' => ['path', 'relative', false],
    'path with NUL' => ['path', "/srv/orbit\0/database.sqlite", false],
    'username' => ['username', 'orbit-user', true],
    'username with newline' => ['username', "orbit\nuser", false],
    'username with trailing newline' => ['username', "orbit-user\n", false],
]);

it('requires the Node settings member while accepting all clearing forms', function (): void {
    $rules = new UpdateNodeSettingsRequest()->rules();
    $factory = new Factory(new Translator(new ArrayLoader, 'en'));

    expect($factory->make([], $rules)->fails())->toBeTrue();

    foreach (['{"apps":null}', '{"apps":{}}', '{"apps":{"path":null}}'] as $json) {
        $request = UpdateNodeSettingsRequest::create('/api/v1/nodes/1/settings', 'PATCH', content: $json);
        $patch = $request->payload();

        expect($patch->hasApps)->toBeTrue();
        expect($patch->apps?->path)->toBeNull();
        expect($factory->make($request->validationData(), $rules)->passes())->toBeTrue();
    }
});

it('publishes empty-object bodies with the runtime requiredness', function (string $operationId, bool $required): void {
    $root = dirname(__DIR__, 4);
    $openapi = json_decode((string) file_get_contents($root.'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
    $catalogue = json_decode((string) file_get_contents($root.'/apps/gateway/resources/mcp/tools.json'), true, flags: JSON_THROW_ON_ERROR);
    $operations = collect($openapi['paths'])->flatMap(fn (array $path): array => array_values($path))->keyBy('operationId');
    $tools = collect($catalogue['tools'])->keyBy('name');
    $operation = $operations->get($operationId);
    $tool = $tools->get($operationId);

    expect($operation['requestBody']['content']['application/json']['schema'])->toBe(['type' => 'object', 'additionalProperties' => false]);
    expect($operation['requestBody']['required'])->toBe($required);
    expect($tool['input_schema']['additionalProperties'])->toBeFalse();
    expect(array_keys($tool['input_schema']['properties']))->toBe($tool['path_inputs']);
    expect($tool['input_schema']['required'])->toBe($tool['path_inputs']);
    expect($tool['query_inputs'])->toBe([]);
})->with([
    'deploy' => ['instance-deploy', true],
    'environment sync' => ['env-sync', true],
    'dependency scan' => ['instance-dependencies-scan', true],
    'dependency update' => ['instance-dependencies-update', true],
    'task cancellation' => ['tasks-cancel', false],
    'task completion' => ['tasks-complete', false],
    'schedule enable' => ['schedule-enable', false],
]);

it('keeps bodyless operations without a request body', function (): void {
    $root = dirname(__DIR__, 4);
    $openapi = json_decode((string) file_get_contents($root.'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
    $operations = collect($openapi['paths'])->flatMap(fn (array $path): array => array_values($path))->keyBy('operationId');

    expect($operations->get('extension-enable'))->not->toHaveKey('requestBody');
    expect($operations->get('instance-dependencies-show'))->not->toHaveKey('requestBody');
});
