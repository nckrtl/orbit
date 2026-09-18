<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Sdk\GatewayConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Recorded Gateway responses under packages/php-sdk/fixtures are the contract the CLI replays.
 * The Gateway test suite records them; see apps/gateway/tests/Support/ResponseFixtures.php.
 */
function gateway_fixture_path(string $name): string
{
    return base_path('../../packages/php-sdk/fixtures/'.$name.'.json');
}

/** @return array{schema: int, request: class-string, route: string, status: int, body: string} The body stays JSON text so `{}` and `[]` reach the CLI as the Gateway sent them. */
function gateway_fixture(string $name): array
{
    $path = gateway_fixture_path($name);
    if (! is_file($path)) {
        throw new RuntimeException("Gateway fixture {$name} is not recorded. Run ORBIT_FIXTURES=record vendor/bin/pest --filter=Fixtures in apps/gateway.");
    }

    $fixture = json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);

    return [
        'schema' => $fixture->schema,
        'request' => $fixture->request,
        'route' => $fixture->route,
        'status' => $fixture->status,
        'body' => json_encode($fixture->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    ];
}

/** @return array<class-string, MockResponse> Saloon mock entries keyed by the SDK request class, one per fixture. */
function gateway_fixture_mock(string ...$names): array
{
    $mocks = [];
    foreach ($names as $name) {
        $fixture = gateway_fixture($name);
        $mocks[$fixture['request']] = MockResponse::make($fixture['body'], $fixture['status'], ['Content-Type' => 'application/json']);
    }

    return $mocks;
}

/**
 * Compare command output with the expected file under tests/Expected, or rewrite it with ORBIT_EXPECTED=update.
 */
function expect_output(string $actual, string $name): void
{
    $path = base_path('tests/Expected/'.$name);
    $previewDirectory = getenv('ORBIT_EXPECTED_DIR');
    if (getenv('ORBIT_EXPECTED') === 'update' && is_string($previewDirectory) && $previewDirectory !== '') {
        // A preview writes the current output next to nothing committed, so bin/cli-contract can diff it.
        $path = rtrim($previewDirectory, '/').'/'.$name;
    }
    if (getenv('ORBIT_EXPECTED') === 'update') {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $actual);

        return;
    }

    expect(is_file($path))->toBeTrue("Expected output {$name} is not recorded. Run ORBIT_EXPECTED=update vendor/bin/pest --filter=Contract in apps/cli.");
    expect($actual)->toBe((string) file_get_contents($path), "Command output differs from tests/Expected/{$name}. Review the change, then run ORBIT_EXPECTED=update vendor/bin/pest --filter=Contract in apps/cli.");
}

/**
 * @param  string|list<string>  $fixtures
 * @param  array<string, mixed>  $arguments
 */
function run_contract(string|array $fixtures, string $command, array $arguments, string $expected, int $exitCode): void
{
    // A global mock keeps its first responses, so replace it for every replay.
    MockClient::destroyGlobal();
    MockClient::global(gateway_fixture_mock(...(array) $fixtures));

    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(Artisan::output(), $expected);
}

/**
 * A `Closure(object, string): object` backed by a real `GatewayConnector` replaying the named
 * fixtures: the same shape `GatewayCommand::sendOrThrow()` and `TopCommand`'s own `$send` use.
 * Lets a unit test exercise a `Sources\Gateway*Source` through the real SDK request and its
 * `createDtoFromResponse()` mapping, without a command or Artisan.
 */
function gateway_fixture_send(string ...$names): Closure
{
    MockClient::destroyGlobal();
    MockClient::global(gateway_fixture_mock(...$names));
    $connector = new GatewayConnector('https://10.44.0.1');

    return function (object $request, string $responseClass) use ($connector): object {
        $dto = $connector->send($request)->dto();
        assert($dto instanceof $responseClass);

        return $dto;
    };
}
