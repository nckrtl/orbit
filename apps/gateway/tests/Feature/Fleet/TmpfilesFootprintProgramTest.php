<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use Symfony\Component\Process\Process;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\FleetTestSsh;
use Tests\Support\Fleet\ScriptedSshExecutor;
use Tests\Support\LinuxHost;

pest()->group('privileged');

it('writes the tmpfiles rule as root once and replaces a changed copy', function (): void {
    if (LinuxHost::delegate($this)) {
        return;
    }

    $directory = sys_get_temp_dir().'/orbit-tmpfiles-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $path = "$directory/orbit.conf";
    $ssh = new ScriptedSshExecutor;
    // Run the real program, with the rule's path moved into the test directory.
    $ssh->on('/sudo bash/', static function (RemoteCommand $command) use ($path): CommandResult {
        $arguments = $command->arguments;
        $arguments[4] = $path;
        $process = new Process($arguments)->setInput($command->input);
        $process->run();

        return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
    });
    $artifact = new TmpfilesFootprintArtifact(FleetTestSsh::shell($ssh));
    $node = FleetFixtures::node('dev', [RoleName::AppDev]);
    $owner = static fn (): string => trim(new Process(['stat', '-c', '%U:%G:%a', $path])->mustRun()->getOutput());

    try {
        expect($artifact->apply($node))->toBeTrue()
            ->and(file_get_contents($path))->toBe(TmpfilesFootprintArtifact::Rule)
            ->and($owner())->toBe('root:root:644')
            ->and($artifact->apply($node))->toBeFalse();

        new Process(['sudo', '-n', 'bash', '-c', 'printf "e /tmp/other-* - - - 1d\n" >> "$1"', '--', $path])->mustRun();

        expect($artifact->apply($node))->toBeTrue()
            ->and(file_get_contents($path))->toBe(TmpfilesFootprintArtifact::Rule)
            ->and(glob("$directory/orbit.conf.*"))->toBe([]);
    } finally {
        new Process(['sudo', '-n', 'rm', '-rf', '--', $directory])->mustRun();
    }
});
