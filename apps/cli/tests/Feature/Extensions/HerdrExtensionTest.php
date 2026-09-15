<?php

declare(strict_types=1);

use App\Commands\Herdr\CreateHerdrSessionCommand;
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

it('hides and refuses Herdr commands until the extension is enabled', function (): void {
    expect(app(CreateHerdrSessionCommand::class)->isHidden())->toBeTrue();

    $this->artisan('herdr:session:create', [
        'session' => 'commander-tasks',
        '--node' => 'beast',
        '--user' => 'nckrtl',
        '--json' => true,
    ])->assertExitCode(1)->expectsOutputToContain('extension.disabled');

    $this->artisan('extension:enable', ['extension' => 'herdr', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"enabled":true');

    expect(app(CreateHerdrSessionCommand::class)->isHidden())->toBeFalse()
        ->and(app(LocalExtensionState::class)->enabled('herdr'))->toBeTrue();

    $this->artisan('extension:disable', ['extension' => 'herdr', '--json' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('"enabled":false');

    expect(app(CreateHerdrSessionCommand::class)->isHidden())->toBeTrue();
});

it('lists Herdr as an opt-in extension', function (): void {
    expect(app(LocalExtensionState::class)->enabled('herdr'))->toBeFalse();
    expect(Artisan::call('extension:list', ['--json' => true]))->toBe(0);
    expect(trim(Artisan::output()))->toBe('{"extensions":[{"extension":"herdr","enabled":false}]}');
});

it('renders extension states as a read-only table without prompting or ANSI', function (): void {
    expect(Artisan::call('extension:list'))->toBe(0);
    expect(Artisan::output())->toContain('EXTENSION', 'STATE', 'herdr', 'disabled')
        ->not->toContain("\e[", 'Press / to search');

    app(LocalExtensionState::class)->enable('herdr');
    expect(Artisan::call('extension:list'))->toBe(0);
    expect(Artisan::output())->toContain('herdr', 'enabled')->not->toContain('disabled');
});

it('reports idempotent local extension changes with their actual resulting state', function (string $verb, bool $enabled): void {
    foreach ([1, 2] as $attempt) {
        expect(Artisan::call('extension:'.$verb, ['extension' => 'herdr']))->toBe(0);
        expect(Artisan::output())->toContain('Extension: herdr', $enabled ? '● Enabled extension' : '● Disabled extension')
            ->not->toContain("\e[");
        expect(app(LocalExtensionState::class)->enabled('herdr'))->toBe($enabled);
    }
})->with([['enable', true], ['disable', false]]);

it('rejects extension state readable by other users', function (): void {
    mkdir($this->orbitHome, 0700, true);
    $path = $this->orbitHome.'/extensions.json';
    file_put_contents($path, '{"enabled":["herdr"]}');
    chmod($path, 0644);

    expect(fn (): bool => app(LocalExtensionState::class)->enabled('herdr'))
        ->toThrow(GatewayConfigException::class, 'Orbit extension configuration is not private.');
});

it('rejects extension state in a public directory', function (): void {
    mkdir($this->orbitHome, 0755, true);
    chmod($this->orbitHome, 0755);
    $path = $this->orbitHome.'/extensions.json';
    file_put_contents($path, '{"enabled":["herdr"]}');
    chmod($path, 0600);

    expect(fn (): bool => app(LocalExtensionState::class)->enabled('herdr'))
        ->toThrow(GatewayConfigException::class, 'Orbit gateway configuration directory is not private.');
});

it('rejects symbolic-link extension state without changing its target', function (): void {
    mkdir($this->orbitHome, 0700, true);
    $target = $this->orbitHome.'/target.json';
    file_put_contents($target, '{"enabled":["herdr"]}');
    chmod($target, 0600);
    symlink($target, $this->orbitHome.'/extensions.json');

    expect(fn (): bool => app(LocalExtensionState::class)->enabled('herdr'))
        ->toThrow(GatewayConfigException::class, 'Orbit extension configuration is not private.');
    expect(file_get_contents($target))->toBe('{"enabled":["herdr"]}');
});

it('returns stable JSON when the extension state path is a directory', function (): void {
    mkdir($this->orbitHome, 0700, true);
    mkdir($this->orbitHome.'/extensions.json', 0700);

    $this->artisan('extension:enable', ['extension' => 'herdr', '--json' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('"code":"extension.config_invalid"')
        ->doesntExpectOutputToContain($this->orbitHome);
});

it('rejects invalid extension state documents', function (string $contents): void {
    mkdir($this->orbitHome, 0700, true);
    file_put_contents($this->orbitHome.'/extensions.json', $contents);
    chmod($this->orbitHome.'/extensions.json', 0600);

    expect(fn (): bool => app(LocalExtensionState::class)->enabled('herdr'))
        ->toThrow(GatewayConfigException::class, 'Orbit extension configuration is invalid.');
})->with([
    'malformed JSON' => '{',
    'unknown extension' => '{"enabled":["unknown"]}',
    'unexpected key' => '{"enabled":[],"unexpected":true}',
    'oversized state' => str_repeat(' ', 65_537),
]);

it('keeps Herdr commands hidden and returns stable JSON for invalid extension state', function (
    string $command,
    array $arguments,
): void {
    mkdir($this->orbitHome, 0700, true);
    file_put_contents($this->orbitHome.'/extensions.json', '{');
    chmod($this->orbitHome.'/extensions.json', 0600);

    expect(app(CreateHerdrSessionCommand::class)->isHidden())->toBeTrue();

    $this->artisan($command, [...$arguments, '--json' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('"code":"extension.config_invalid"')
        ->doesntExpectOutputToContain($this->orbitHome);
})->with([
    'create' => ['herdr:session:create', ['session' => 'commander-tasks']],
    'adopt' => ['herdr:session:adopt', ['session' => 'commander-tasks']],
    'list' => ['herdr:session:list', []],
    'show' => ['herdr:session:show', ['session' => 'commander-tasks']],
    'restart' => ['herdr:session:restart', ['session' => 'commander-tasks']],
    'destroy' => ['herdr:session:destroy', ['session' => 'commander-tasks']],
    'observe' => ['herdr:observe', ['session' => 'commander-tasks']],
]);

it('returns stable JSON when extension management encounters invalid state', function (
    string $command,
    array $arguments,
): void {
    mkdir($this->orbitHome, 0700, true);
    file_put_contents($this->orbitHome.'/extensions.json', '{');
    chmod($this->orbitHome.'/extensions.json', 0600);

    $this->artisan($command, [...$arguments, '--json' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('"code":"extension.config_invalid"')
        ->doesntExpectOutputToContain($this->orbitHome);
})->with([
    'enable' => ['extension:enable', ['extension' => 'herdr']],
    'disable' => ['extension:disable', ['extension' => 'herdr']],
    'list' => ['extension:list', []],
]);
