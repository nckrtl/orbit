<?php

declare(strict_types=1);

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

/** @return array<class-string, MockResponse> One Saloon mock entry keyed by the SDK request class. */
function gateway_fixture_mock(string $name): array
{
    $fixture = gateway_fixture($name);

    return [$fixture['request'] => MockResponse::make($fixture['body'], $fixture['status'], ['Content-Type' => 'application/json'])];
}

/**
 * Compare command output with the expected file under tests/Expected, or rewrite it with ORBIT_EXPECTED=update.
 */
function expect_output(string $actual, string $name): void
{
    $path = base_path('tests/Expected/'.$name);
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
