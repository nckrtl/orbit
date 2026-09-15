<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-output-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    $repository = app(GatewayConfigRepository::class);
    $repository->add(new GatewayProfile('primary', 'https://127.0.0.1:1'));
    $repository->add(new GatewayProfile('secondary', 'https://127.0.0.1:2'));
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('local profile and extension output channels', function (): void {
    it('reports a removed profile and failed certificate cleanup without a stack trace', function (bool $json): void {
        $directory = $this->orbitHome.'/certificate';
        mkdir($directory, 0700);
        $certificate = $directory.'/pin.pem';
        file_put_contents($certificate, "disposable certificate\n");
        app(GatewayConfigRepository::class)->add(new GatewayProfile('secondary', 'https://127.0.0.1:2', $certificate));
        chmod($directory, 0500);

        try {
            $arguments = [PHP_BINARY, base_path('orbit'), 'gateway:remove', 'secondary', '--yes'];

            if ($json) {
                $arguments[] = '--json';
            }

            $process = new Process($arguments, base_path(), ['ORBIT_HOME' => $this->orbitHome]);
            expect($process->run())->toBe(1)
                ->and($process->getErrorOutput())->toBe('');
            $output = $process->getOutput();
            expect($output)->toContain('Gateway profile was removed, but its pinned certificate could not be deleted.')
                ->not->toContain($certificate, 'ErrorException', 'Stack trace', 'Gateway [secondary] removed.');

            if ($json) {
                expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'error' => [
                        'code' => 'gateway.config_invalid',
                        'message' => 'Gateway profile was removed, but its pinned certificate could not be deleted.',
                        'request_id' => null,
                    ],
                ]);
            }

            expect(app(GatewayConfigRepository::class)->find('secondary'))->toBeNull()
                ->and(file_get_contents($certificate))->toBe("disposable certificate\n");
        } finally {
            chmod($directory, 0700);
        }
    })->with([false, true])->skip(fn (): bool => posix_geteuid() === 0, 'Requires unprivileged filesystem permission checks.');

    it('keeps JSON on stdout without terminal output even with forced decoration', function (array $arguments, array $expected, int $exit): void {
        $process = new Process([PHP_BINARY, base_path('orbit'), ...$arguments, '--json', '--ansi'], base_path(), [
            'ORBIT_HOME' => $this->orbitHome,
        ]);
        expect($process->run())->toBe($exit);
        expect($process->getErrorOutput())->toBe('');
        expect($process->getOutput())->not->toContain("\e[", 'Working...', 'Removing profile', 'Requesting status');
        $payload = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        if ($exit === 0) {
            expect($payload)->toBe($expected);
        } else {
            expect($payload['error']['code'])->toBe($expected['code'])
                ->and($payload['error']['request_id'])->toBeNull();
        }
    })->with([
        'use' => [['gateway:use', 'secondary'], ['active_gateway' => 'secondary'], 0],
        'remove' => [['gateway:remove', 'secondary', '--yes'], ['profile' => 'secondary'], 0],
        'consent' => [['gateway:remove', 'secondary'], ['code' => 'input.confirmation_required'], 1],
        'force without consent' => [['gateway:remove', 'primary', '--force'], ['code' => 'input.confirmation_required'], 1],
        'enable' => [['extension:enable', 'herdr'], ['extension' => 'herdr', 'enabled' => true], 0],
        'disable' => [['extension:disable', 'herdr'], ['extension' => 'herdr', 'enabled' => false], 0],
        'list' => [['extension:list'], ['extensions' => [['extension' => 'herdr', 'enabled' => false]]], 0],
        'status transport error' => [['gateway:status'], ['code' => 'gateway.unreachable'], 1],
        'missing profile argument' => [['gateway:use'], ['code' => 'input.invalid'], 1],
        'missing removal argument' => [['gateway:remove', '--yes'], ['code' => 'input.invalid'], 1],
        'missing enable argument' => [['extension:enable'], ['code' => 'input.invalid'], 1],
        'missing disable argument' => [['extension:disable'], ['code' => 'input.invalid'], 1],
    ]);

    it('keeps piped human output plain and settles each operation once', function (array $arguments, string $outcome, int $exit): void {
        $process = new Process([PHP_BINARY, base_path('orbit'), ...$arguments, '--ansi', '--no-interaction'], base_path(), [
            'ORBIT_HOME' => $this->orbitHome,
        ]);
        expect($process->run())->toBe($exit);
        expect($process->getErrorOutput())->toBe('');
        expect($process->getOutput())->not->toContain("\e[", 'Working...');
        expect(substr_count($process->getOutput(), $outcome))->toBe(1);
    })->with([
        'use' => [['gateway:use', 'secondary'], 'Gateway [secondary] is active.', 0],
        'remove' => [['gateway:remove', 'secondary', '--yes'], 'Gateway [secondary] removed.', 0],
        'refuse' => [['gateway:remove', 'secondary'], 'Supply --yes', 1],
        'enable' => [['extension:enable', 'herdr'], 'Orbit extension [herdr] is enabled.', 0],
        'disable' => [['extension:disable', 'herdr'], 'Orbit extension [herdr] is disabled.', 0],
        'list' => [['extension:list'], 'herdr', 0],
        'status' => [['gateway:status'], 'Could not reach the gateway.', 1],
    ]);
});
