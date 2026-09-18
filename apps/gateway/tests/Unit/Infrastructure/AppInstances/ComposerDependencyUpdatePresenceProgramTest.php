<?php

declare(strict_types=1);

use App\Infrastructure\AppInstances\ComposerDependencyUpdatePresenceProgram;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function composer_update_presence_directory(): string
{
    $path = sys_get_temp_dir().'/orbit-composer-update-presence-'.Str::uuid();
    mkdir($path, 0o700);

    return $path;
}

/** @return array<string, mixed> */
function composer_update_presence(string $path): array
{
    $process = new Process(['/usr/bin/python3', '-I', '-', $path], timeout: 10);
    $process->setInput(ComposerDependencyUpdatePresenceProgram::render());
    $process->mustRun();
    expect($process->getErrorOutput())->toBeEmpty();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

describe('Composer update presence probe', function (): void {
    it('reports present, absent and incomplete Composer roots without executing files', function (): void {
        $root = composer_update_presence_directory();
        try {
            expect(composer_update_presence($root))->toBe(['status' => 'absent']);
            file_put_contents($root.'/composer.json', '{"scripts":{"pre-update-cmd":"touch NEVER"}}');
            expect(composer_update_presence($root))->toBe(['status' => 'incomplete']);
            file_put_contents($root.'/composer.lock', '{"packages":[]}');
            expect(composer_update_presence($root))->toBe(['status' => 'present']);
            expect(file_exists($root.'/NEVER'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('rejects a symlink Composer manifest', function (): void {
        $root = composer_update_presence_directory();
        try {
            file_put_contents($root.'/target.json', '{}');
            symlink('target.json', $root.'/composer.json');
            file_put_contents($root.'/composer.lock', '{}');
            expect(composer_update_presence($root))->toBe(['error' => 'dependencies.unsafe_source']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });
});
