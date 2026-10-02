<?php

declare(strict_types=1);

use App\Infrastructure\Tasks\TaskWorkspaceMetadata;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

describe('TaskGitHardening', function (): void {
    it('loads trusted metadata IO without importing workspace Python programs', function (): void {
        $checkout = sys_get_temp_dir().'/orbit-metadata-import-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($checkout.'/.git');
        file_put_contents($checkout.'/json.py', "open('import-ran', 'w').write('Node code execution')\n");
        $program = "checkout=\$1\n".TaskWorkspaceMetadata::bashPreamble().TaskWorkspaceMetadata::operation('snapshot', ['script' => base64_encode('trusted check')]);
        $process = new Process(['bash', '-seu', '--', $checkout], cwd: $checkout, env: ['PYTHONPATH' => $checkout], input: $program);

        try {
            $process->mustRun();

            expect(file_exists($checkout.'/import-ran'))->toBeFalse()
                ->and(file_get_contents($checkout.'/.git/orbit/check'))->toBe('trusted check');
        } finally {
            File::deleteDirectory($checkout);
        }
    });

    it('pins metadata descriptors against deterministic worker substitution', function (string $operation, string $substitution): void {
        $root = sys_get_temp_dir().'/orbit-metadata-race-'.bin2hex(random_bytes(6));
        $checkout = $root.'/checkout';
        $private = sys_get_temp_dir().'/orbit-metadata-private-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($checkout.'/.git/orbit');
        File::ensureDirectoryExists($checkout.'/.git/info');
        File::ensureDirectoryExists($private, 0700);
        file_put_contents($private.'/private', 'private Node file');
        chmod($private.'/private', 0600);
        (new Process(['setfacl', '-m', 'u:nobody:rwx', $root]))->mustRun();
        (new Process(['setfacl', '-R', '-m', 'u:nobody:rwX,d:u:nobody:rwX,d:u:'.posix_geteuid().':rwX', $checkout]))->mustRun();
        $payload = match ($operation) {
            'snapshot' => ['script' => base64_encode('trusted check program')],
            'check' => ['script' => base64_encode('trusted check program'), 'setup' => '[]', 'command' => 'true', 'deliverables' => null],
            'turn' => ['script' => base64_encode('trusted turn program'), 'turn' => '{}', 'context' => 'Task context'],
            'mcp' => ['config' => '{}'],
        };
        $probe = new Process(['python3', '-c', <<<'PYTHON'
            import importlib.machinery, importlib.util, json, os, subprocess, sys
            loader = importlib.machinery.SourceFileLoader('metadata', sys.argv[1])
            module = importlib.util.module_from_spec(importlib.util.spec_from_loader('metadata', loader))
            loader.exec_module(module)
            checkout, private, operation, substitution = sys.argv[2:]
            original_write = module.os.write
            def substituted_write(descriptor, data):
                module.os.write = original_write
                if substitution == 'candidate':
                    directory = os.path.join(checkout, '.git', 'orbit') if operation != 'mcp' else os.path.join(checkout, '.git', 'info')
                    name = next(name for name in os.listdir(directory) if name.startswith('.orbit-publish-'))
                    path, target = os.path.join(directory, name), os.path.join(private, 'private')
                    action = 'os.unlink(path); os.symlink(target, path)'
                else:
                    path = checkout if substitution == 'checkout' else os.path.join(checkout, '.git' if substitution == 'git' else '.git/' + substitution)
                    target = private
                    action = 'os.rename(path, path + ".saved"); os.symlink(target, path)'
                attacker = subprocess.run(['sudo', '-n', '-u', 'nobody', '-H', '--', 'python3', '-c',
                    'import os, sys; path, target = sys.argv[1:]; ' + action + '; print(os.geteuid())', path, target], check=True, capture_output=True, text=True)
                print(attacker.stdout.strip(), flush=True)
                return original_write(descriptor, data)
            module.os.write = substituted_write
            module.perform(checkout, operation, json.load(sys.stdin))
            PYTHON, resource_path('tasks/metadata'), $checkout, $private, $operation, $substitution], input: json_encode($payload, JSON_THROW_ON_ERROR));

        try {
            $probe->run();

            expect($probe->getExitCode())->not->toBe(0)
                ->and($probe->getOutput())->toContain('65534')
                ->and($probe->getErrorOutput())->toContain('replaced')
                ->and(file_get_contents($private.'/private'))->toBe('private Node file')
                ->and(scandir($private))->toBe(['.', '..', 'private']);
        } finally {
            File::deleteDirectory($root);
            File::deleteDirectory($private);
        }
    })->with([
        'check candidate' => ['check', 'candidate'],
        'snapshot candidate' => ['snapshot', 'candidate'],
        'turn candidate' => ['turn', 'candidate'],
        'MCP candidate' => ['mcp', 'candidate'],
        'orbit directory' => ['check', 'orbit'],
        'git directory' => ['check', 'git'],
        'checkout directory' => ['snapshot', 'checkout'],
        'exclude directory' => ['mcp', 'info'],
    ]);
});
