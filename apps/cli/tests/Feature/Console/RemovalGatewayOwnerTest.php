<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Apps\DestroyProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\DestroyScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Processes\DestroyProcessRequest;
use Orbit\Sdk\Requests\Schedules\DestroyScheduleRequest;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Requests\Tools\ShowToolRequest;
use Symfony\Component\Process\Process;

function removal_gateway_owner_case(string $family): array
{
    $uuid = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $tool = ['id' => 41, 'node_id' => 12, 'manager' => 'apt', 'package' => 'curl',
        'version_constraint' => null, 'protected' => false, 'status' => 'installed',
        'installed_version' => '8.0', 'failed_operation' => null, 'error_code' => null, 'outcome' => null];
    $definition = ['id' => $uuid, 'app_id' => 7, 'name' => 'worker',
        'environments' => ['production'], 'spec' => ['command' => ['/usr/bin/true']]];

    return match ($family) {
        'tool' => [
            'arguments' => ['tool:remove', '41'],
            'prompt' => 'Remove Tool [curl] (apt on Node #12) by uninstalling it and deleting its record?',
            'mutation' => RemoveToolRequest::class,
            'path' => '/api/v1/tools/41',
            'reads' => 1,
            'replies' => [removal_gateway_owner_reply(ShowToolRequest::class, $tool),
                removal_gateway_owner_reply(RemoveToolRequest::class, [...$tool, 'status' => 'removed', 'outcome' => 'applied'])],
        ],
        'process' => [
            'arguments' => ['process:destroy', '41'],
            'prompt' => 'Destroy Process [41] and remove its runtime artifacts?',
            'mutation' => DestroyProcessRequest::class,
            'path' => '/api/v1/processes/41',
            'reads' => 0,
            'replies' => [removal_gateway_owner_reply(DestroyProcessRequest::class, [
                'id' => 41, 'target_type' => 'node', 'target_id' => 12, 'name' => 'worker',
                'runtime' => 'systemd', 'working_directory' => '/home/orbit', 'runtime_config' => [],
                'restart_policy' => 'never', 'keep_alive' => false, 'desired_state' => 'stopped',
                'status' => 'removed', 'runtime_status' => 'stopped', 'failed_step' => null, 'error_code' => null,
            ])],
        ],
        'schedule' => [
            'arguments' => ['schedule:destroy', $uuid],
            'prompt' => "Destroy Schedule [{$uuid}] and remove its timer artifacts?",
            'mutation' => DestroyScheduleRequest::class,
            'path' => '/api/v1/schedules/'.$uuid,
            'reads' => 0,
            'replies' => [removal_gateway_owner_reply(DestroyScheduleRequest::class, [
                'id' => $uuid, 'target_type' => 'node', 'target_id' => 12, 'name' => 'worker',
                'calendar' => 'daily', 'command' => 'true', 'timeout_seconds' => 900,
                'desired_timer_state' => 'disabled', 'status' => 'removing',
                'failed_step' => null, 'error_code' => null, 'last_run_at' => null, 'last_run_status' => null,
            ])],
        ],
        'process definition' => [
            'arguments' => ['process:destroy', 'worker', '--project=7'],
            'prompt' => 'Destroy Process definition [worker]?',
            'mutation' => DestroyProcessDefinitionRequest::class,
            'path' => '/api/v1/projects/7/process-definitions/worker',
            'reads' => 0,
            'replies' => [removal_gateway_owner_reply(DestroyProcessDefinitionRequest::class, $definition)],
        ],
        'schedule definition' => [
            'arguments' => ['schedule:destroy', 'worker', '--app=7'],
            'prompt' => 'Destroy Schedule definition [worker]?',
            'mutation' => DestroyScheduleDefinitionRequest::class,
            'path' => '/api/v1/projects/7/schedule-definitions/worker',
            'reads' => 0,
            'replies' => [removal_gateway_owner_reply(DestroyScheduleDefinitionRequest::class, $definition)],
        ],
    };
}

function removal_gateway_owner_reply(string $class, array $data): array
{
    return ['class' => $class, 'body' => ['data' => $data,
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]];
}

describe('removal Gateway ownership', function (): void {
    it('keeps consent bound to its resolved Gateway after the active profile changes', function (
        string $family, array $keys, bool $accepted,
    ): void {
        $case = removal_gateway_owner_case($family);
        $directory = sys_get_temp_dir().'/orbit-removal-owner-'.Str::uuid();
        $repository = new GatewayConfigRepository($directory.'/home/config.json');
        $repository->add(new GatewayProfile('fixture', 'https://fixture.invalid', '/fixture/ca.pem'));
        $repository->add(new GatewayProfile('other', 'https://other.invalid', '/other/ca.pem'));
        $configuration = [...$case, 'arguments' => [...$case['arguments'], '--no-ansi'],
            'home' => $directory.'/home', 'trace' => $directory.'/requests.jsonl',
            'keys' => array_map(base64_encode(...), $keys), 'switch_gateway' => 'other'];
        file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
        $fixtures = __DIR__.'/../../Fixtures/Console/';

        try {
            $process = new Process(['python3', $fixtures.'command-consent-pty.py', PHP_BINARY,
                $fixtures.'command-consent.php', $directory.'/case.json'], env: ['PAO_DISABLE' => '1']);
            $process->setTimeout(20);
            $process->run();
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $output = base64_decode($result['raw']);
            $requests = is_file($configuration['trace']) ? array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($configuration['trace'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
            ) : [];

            expect($result['gateway_switched'])->toBeTrue()
                ->and($repository->active()?->name)->toBe('other')
                ->and($result['status'])->toBe($accepted ? 0 : 1)
                ->and($result['prompt_seen'])->toBeTrue()
                ->and($result['keys_remaining'])->toBe(0)
                ->and($result['restored'])->toBeTrue()
                ->and($requests)->toHaveCount($case['reads'] + ($accepted ? 1 : 0));

            foreach ($requests as $request) {
                expect($request['url'])->toStartWith('https://fixture.invalid/');
            }

            if ($accepted) {
                $mutation = $requests[array_key_last($requests)];
                expect($mutation['class'])->toBe($case['mutation'])
                    ->and($mutation['method'])->toBe('DELETE')
                    ->and($mutation['url'])->toBe('https://fixture.invalid'.$case['path'])
                    ->and($output)->toContain('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
            } else {
                expect($output)->toContain('cancelled.');
            }
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    })->with(['tool', 'process', 'schedule', 'process definition', 'schedule definition'])->with([
        'Yes' => [['y', "\r"], true],
        'default No' => [["\r"], false],
        'No' => [['n', "\r"], false],
        'Ctrl-C' => [["\x03"], false],
        'EOF' => [["\x04"], false],
    ]);
});
