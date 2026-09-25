<?php

declare(strict_types=1);

use App\Commands\ProxyCli\StatusProxyCliCommand;
use App\Exceptions\GatewayConfigException;
use App\Services\Extensions\LocalExtensionState;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-extension-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(LocalExtensionState::class);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->orbitHome);
});

function local_extension_state_file(string $home, string $contents): string
{
    if (! is_dir($home)) {
        mkdir($home, 0700, true);
    }

    $path = $home.'/extensions.json';
    file_put_contents($path, $contents);
    chmod($path, 0600);

    return $path;
}

it('renders extension states as a read-only table without prompting or ANSI', function (): void {
    expect(Artisan::call('extension:list'))->toBe(0);
    expect(Artisan::output())->toContain('EXTENSION', 'STATE', 'proxycli', 'disabled')
        ->not->toContain("\e[", 'Press / to search');

    app(LocalExtensionState::class)->enable('proxycli');
    expect(Artisan::call('extension:list', ['--json' => true]))->toBe(0);
    expect(trim(Artisan::output()))->toBe('{"extensions":[{"extension":"proxycli","enabled":true}]}');
});

it('reports idempotent local extension changes with their actual resulting state', function (string $verb, bool $enabled): void {
    foreach ([1, 2] as $attempt) {
        expect(Artisan::call('extension:'.$verb, ['extension' => 'proxycli']))->toBe(0);
        expect(Artisan::output())->toContain('Extension: proxycli', $enabled ? '● Enabled extension' : '● Disabled extension')
            ->not->toContain("\e[");
        expect(app(LocalExtensionState::class)->enabled('proxycli'))->toBe($enabled);
    }
})->with([['enable', true], ['disable', false]]);

it('ignores an extension slug this CLI does not know', function (): void {
    local_extension_state_file($this->orbitHome, '{"enabled":["retired-extension","proxycli"]}');

    expect(app(LocalExtensionState::class)->enabled('proxycli'))->toBeTrue()
        ->and(app(StatusProxyCliCommand::class)->isHidden())->toBeFalse();
    expect(Artisan::call('extension:list', ['--json' => true]))->toBe(0);
    expect(trim(Artisan::output()))->toBe('{"extensions":[{"extension":"proxycli","enabled":true}]}');
});

it('drops an unknown extension slug on the next write', function (string $verb, array $expected): void {
    $path = local_extension_state_file($this->orbitHome, '{"enabled":["retired-extension","proxycli"]}');

    $this->artisan('extension:'.$verb, ['extension' => 'proxycli', '--json' => true])->assertExitCode(0);

    expect(json_decode((string) file_get_contents($path), true))->toBe(['enabled' => $expected]);
})->with([
    'enable' => ['enable', ['proxycli']],
    'disable' => ['disable', []],
]);

it('rejects extension state readable by other users', function (): void {
    $path = local_extension_state_file($this->orbitHome, '{"enabled":["proxycli"]}');
    chmod($path, 0644);

    expect(fn (): bool => app(LocalExtensionState::class)->enabled('proxycli'))
        ->toThrow(GatewayConfigException::class, 'Orbit extension configuration is not private.');
});

it('rejects extension state in a public directory', function (): void {
    local_extension_state_file($this->orbitHome, '{"enabled":["proxycli"]}');
    chmod($this->orbitHome, 0755);

    expect(fn (): bool => app(LocalExtensionState::class)->enabled('proxycli'))
        ->toThrow(GatewayConfigException::class, 'Orbit gateway configuration directory is not private.');
});

it('rejects symbolic-link extension state without changing its target', function (): void {
    mkdir($this->orbitHome, 0700, true);
    $target = $this->orbitHome.'/target.json';
    file_put_contents($target, '{"enabled":["proxycli"]}');
    chmod($target, 0600);
    symlink($target, $this->orbitHome.'/extensions.json');

    expect(fn (): bool => app(LocalExtensionState::class)->enabled('proxycli'))
        ->toThrow(GatewayConfigException::class, 'Orbit extension configuration is not private.');
    expect(file_get_contents($target))->toBe('{"enabled":["proxycli"]}');
});

it('returns stable JSON when the extension state path is a directory', function (): void {
    mkdir($this->orbitHome, 0700, true);
    mkdir($this->orbitHome.'/extensions.json', 0700);

    $this->artisan('extension:enable', ['extension' => 'proxycli', '--json' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('"code":"extension.config_invalid"')
        ->doesntExpectOutputToContain($this->orbitHome);
});

it('rejects invalid extension state documents', function (string $contents): void {
    local_extension_state_file($this->orbitHome, $contents);

    expect(fn (): bool => app(LocalExtensionState::class)->enabled('proxycli'))
        ->toThrow(GatewayConfigException::class, 'Orbit extension configuration is invalid.');
})->with([
    'malformed JSON' => '{',
    'non-string slug' => '{"enabled":[1]}',
    'unexpected key' => '{"enabled":[],"unexpected":true}',
    'oversized state' => str_repeat(' ', 65_537),
]);

it('returns stable JSON when extension management encounters invalid state', function (
    string $command,
    array $arguments,
): void {
    local_extension_state_file($this->orbitHome, '{');

    $this->artisan($command, [...$arguments, '--json' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('"code":"extension.config_invalid"')
        ->doesntExpectOutputToContain($this->orbitHome);
})->with([
    'enable' => ['extension:enable', ['extension' => 'proxycli']],
    'list' => ['extension:list', []],
]);
