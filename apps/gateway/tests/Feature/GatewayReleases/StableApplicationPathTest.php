<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\NativeAgentViewConverger;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Gateway\GatewayCaddyConfigRenderer;
use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

/** Records the content of every unit file a converger installs with `sudo install`. */
final class InstalledUnits implements ProcessRunner
{
    /** @var list<string> */
    public array $installed = [];

    public function run(ProcessInvocation $invocation): CommandResult
    {
        if (array_slice($invocation->arguments, 0, 2) === ['sudo', 'install']) {
            $this->installed[] = (string) file_get_contents($invocation->arguments[4]);
        }

        return new CommandResult(0, '', '', 1, false);
    }
}

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/orbit-stable-path-'.bin2hex(random_bytes(4));
    mkdir($this->base.'/releases/0123456789ab/apps/gateway', 0755, true);
    symlink('releases/0123456789ab', $this->base.'/orbit');
    config(['orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway', 'orbit.home' => $this->base.'/home']);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->base));
});

describe('the stable Gateway application path', function (): void {
    it('is the link to the current release, not the release it resolves to', function (): void {
        expect(GatewayApplicationPath::resolve())->toBe($this->base.'/orbit/apps/gateway');
    });

    it('is what the agent-view and hibernator units run in, so a restart after a switch runs the new release', function (): void {
        $processes = new InstalledUnits;

        new NativeAgentViewConverger($processes)->converge();
        new NativeRuntimeHibernatorConverger($processes)->converge();
        $units = implode("\n", $processes->installed);

        expect($processes->installed)->not->toBe([])
            ->and($units)->toContain('WorkingDirectory='.$this->base.'/orbit/apps/gateway')
            ->and($units)->toContain($this->base.'/orbit/apps/gateway/artisan')
            ->and($units)->not->toContain('releases/0123456789ab');
    });
});

it('resolves the link per request on the Gateway site PHP handler itself', function (): void {
    $site = new GatewayCaddyConfigRenderer()->render('gateway.orbit', '10.44.0.1', '/home/orbit/orbit/apps/gateway', '/home/orbit/web');

    expect($site)->toContain("php_fastcgi unix//run/php/orbit-gateway.sock {\n            resolve_root_symlink\n");
});
