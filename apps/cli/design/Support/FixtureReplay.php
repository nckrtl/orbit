<?php

declare(strict_types=1);

namespace Design\Support;

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use RuntimeException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Replays recorded Gateway responses under a real command in a real terminal.
 *
 * Dev-only, loaded when ORBIT_DESIGN=1 and ORBIT_GATEWAY_FIXTURES names one or more
 * fixtures under packages/php-sdk/fixtures, separated by commas. The replay registers a
 * Saloon mock for each fixture's SDK request class and seeds a Gateway profile into an
 * empty ORBIT_HOME, so the command renders exactly what the contract tests hold, live.
 */
final class FixtureReplay
{
    public static function install(string $names, string $home, ?string $fixtureRoot = null): void
    {
        $root = $fixtureRoot ?? dirname(__DIR__, 4).'/packages/php-sdk/fixtures';
        $fixtures = [];
        foreach (array_filter(array_map(trim(...), explode(',', $names))) as $name) {
            $path = $root.'/'.$name.'.json';
            if (! is_file($path)) {
                throw new RuntimeException("Gateway fixture {$name} is not recorded.");
            }
            $contents = file_get_contents($path);
            if (! is_string($contents)) {
                throw new RuntimeException("Gateway fixture {$name} is not recorded.");
            }
            $decoded = json_decode($contents, flags: JSON_THROW_ON_ERROR);
            if (! is_object($decoded)) {
                throw new RuntimeException("Gateway fixture {$name} is not recorded.");
            }
            $record = get_object_vars($decoded);
            $request = $record['request'] ?? null;
            $status = $record['status'] ?? null;
            if (! array_key_exists('body', $record) || ! is_string($request) || ! is_int($status)) {
                throw new RuntimeException("Gateway fixture {$name} is not recorded.");
            }
            $fixtures[$request] = MockResponse::make(
                json_encode($record['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                $status,
                ['Content-Type' => 'application/json'],
            );
        }

        MockClient::global($fixtures);

        if (! is_dir($home) || ! is_file(rtrim($home, '/').'/config.json')) {
            // A replay home starts empty; give it the profile the contract tests use. The CLI requires a private home.
            if (! is_dir($home)) {
                mkdir($home, 0700, true);
            }
            new GatewayConfigRepository(rtrim($home, '/').'/config.json')->add(new GatewayProfile(
                name: 'replay',
                url: 'https://10.44.0.1',
                caPath: rtrim($home, '/').'/replay-ca.pem',
            ));
        }
    }
}
