<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;

/**
 * Recorded Gateway responses are the contract between the Gateway and its clients.
 *
 * A fixture test sends a request with deterministic data and records the response under
 * packages/php-sdk/fixtures/<name>.json. Normal runs assert that the response still equals
 * the recorded file; `ORBIT_FIXTURES=record` rewrites it. The CLI tests replay the same
 * files through Saloon, so a changed fixture shows which commands the change reaches.
 */
function fixture_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function fixture_path(string $name): string
{
    return base_path('../../packages/php-sdk/fixtures/'.$name.'.json');
}

/**
 * @param  class-string  $request  The SDK request class that sends this route.
 * @param  string  $route  The route as `METHOD /api/v1/path`.
 */
function record_fixture(TestResponse $response, string $name, string $request, string $route): void
{
    $recorded = [
        'schema' => 1,
        'request' => $request,
        'route' => $route,
        'status' => $response->getStatusCode(),
        // Decode to objects so an empty JSON object stays `{}` in the file instead of becoming `[]`.
        'body' => json_decode((string) $response->getContent(), flags: JSON_THROW_ON_ERROR),
    ];
    // A test that generates its own request id still records the fixed one, so the file is stable.
    if (is_object($recorded['body']) && isset($recorded['body']->meta->request_id)) {
        $recorded['body']->meta->request_id = fixture_request_id();
    }
    $path = fixture_path($name);

    if (getenv('ORBIT_FIXTURES') === 'record') {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, json_encode($recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        return;
    }

    expect(is_file($path))->toBeTrue("Fixture {$name} is not recorded. Run ORBIT_FIXTURES=record vendor/bin/pest --filter=Fixtures in apps/gateway.");

    $stored = json_decode((string) file_get_contents($path), flags: JSON_THROW_ON_ERROR);

    expect(['status' => $recorded['status'], 'body' => $recorded['body']])
        ->toEqual(['status' => $stored->status, 'body' => $stored->body], "Fixture {$name} is out of date. Review the change, then run ORBIT_FIXTURES=record vendor/bin/pest --filter=Fixtures in apps/gateway and bin/cli-contract {$name}.");
}
