<?php

declare(strict_types=1);

use App\Services\SelfUpdate\NativeSelfUpdateHost;
use App\Services\SelfUpdate\RunningBinaryKind;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

describe(NativeSelfUpdateHost::class, function (): void {
    beforeEach(function (): void {
        $this->directory = sys_get_temp_dir().'/orbit-self-update-host-'.Str::uuid();
        mkdir($this->directory);
        $this->unit = $this->directory.'/orbit-agent.service';
    });

    afterEach(function (): void {
        new Filesystem()->deleteDirectory($this->directory);
    });

    it('knows a managed Node by the marker the Gateway writes into the agent unit', function (string $contents, bool $managed): void {
        file_put_contents($this->unit, $contents);

        expect(new NativeSelfUpdateHost($this->unit, '/usr/local/bin/orbit-agent')->isManagedNode())->toBe($managed);
    })->with([
        'Gateway unit' => ["# Managed by Orbit: agent\n[Unit]\nDescription=Orbit Node agent\n", true],
        'unit written by someone else' => ["[Unit]\nDescription=Orbit Node agent\n", false],
        'marker on a later line' => ["[Unit]\n# Managed by Orbit: agent\n", false],
    ])->skip(PHP_OS_FAMILY !== 'Linux', 'managed Nodes are Linux');

    it('is not a managed Node without the agent unit', function (): void {
        expect(new NativeSelfUpdateHost($this->unit, '/usr/local/bin/orbit-agent')->isManagedNode())->toBeFalse();
    });

    it('reports a source checkout when no PHAR runs', function (): void {
        $binary = new NativeSelfUpdateHost($this->unit, '/usr/local/bin/orbit-agent')->runningBinary();

        expect($binary->kind)->toBe(RunningBinaryKind::Source)
            ->and($binary->path)->toBeNull();
    });

    it('installs the agent as root', function (): void {
        expect(new NativeSelfUpdateHost($this->unit, '/usr/local/bin/orbit-agent')->agentOwner())->toBe([0, 0]);
    });
});
