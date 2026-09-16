<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\DestroyAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\RegisterAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\TransferAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\DestroyInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\ListInstanceDeployStepsRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Symfony\Component\Process\Process;

function instance_source_reply(string $class, array $data): array
{
    return ['class' => $class, 'body' => ['data' => $data,
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]];
}

function instance_source_consent_case(string $family): array
{
    $step = ['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'php artisan migrate --force', 'timeout_seconds' => 300];
    $instance = ['id' => 11, 'name' => 'source', 'node_id' => 2, 'app_id' => 3, 'status' => 'active',
        'environment' => 'development', 'source_layout' => 'checkout', 'checkout_path' => '/work/source', 'deploy_steps' => [$step]];
    $case = match ($family) {
        'remove' => [
            'arguments' => ['instance:destroy', '11'],
            'prompt' => 'Remove App instance [source] (#11) and delete its owned development source, Route and runtime?',
            'reads' => 1, 'option' => '--yes', 'code' => 'input.confirmation_required',
            'replies' => [instance_source_reply(ShowAppInstanceRequest::class, $instance),
                instance_source_reply(DestroyAppInstanceRequest::class, ['id' => 11, 'name' => 'source', 'force' => false, 'status' => 'completed', 'total' => 1, 'completed' => 1, 'remaining' => 0])],
            'mutation' => DestroyAppInstanceRequest::class, 'body' => [],
        ],
        'step' => [
            'arguments' => ['instance:deploy-step:destroy', '11', 'migrate'],
            'prompt' => 'Remove deploy step [migrate] from App instance [source] (#11)?',
            'reads' => 2, 'option' => '--yes', 'code' => 'input.confirmation_required',
            'replies' => [instance_source_reply(ShowAppInstanceRequest::class, $instance), instance_source_reply(ListInstanceDeployStepsRequest::class, [$step]), instance_source_reply(DestroyInstanceDeployStepRequest::class, $step)],
            'mutation' => DestroyInstanceDeployStepRequest::class, 'body' => [],
        ],
        'transfer' => [
            'arguments' => ['instance:transfer', '11', '8'],
            'prompt' => 'Transfer App instance [source] (#11) from Node #2 to Node [destination] (#8) with downtime and deletion of the old placement?',
            'reads' => 2, 'option' => '--force', 'code' => 'instance.confirmation_required',
            'replies' => [instance_source_reply(ShowAppInstanceRequest::class, $instance),
                instance_source_reply(ShowNodeRequest::class, ['id' => 8, 'name' => 'destination']),
                instance_source_reply(TransferAppInstanceRequest::class, [...$instance, 'node_id' => 8, 'domain' => 'source.test', 'transfer' => ['id' => 11, 'cleanup_completed' => true]])],
            'mutation' => TransferAppInstanceRequest::class, 'body' => ['node_id' => 8],
        ],
        'register' => [
            'arguments' => ['instance:register'],
            'prompt' => 'Transfer source [/work/source] to Orbit ownership, allowing relocation and later removal?',
            'reads' => 0, 'option' => '--yes', 'code' => 'input.confirmation_required',
            'registration_facts' => ['path' => '/work/source', 'repositoryUrl' => 'git@github.com:acme/source.git', 'slug' => 'source', 'defaultBranch' => 'main', 'branch' => 'main', 'root' => 'public', 'layout' => 'checkout', 'commit' => str_repeat('a', 40)],
            'replies' => [instance_source_reply(RegisterAppInstanceRequest::class, ['app' => ['id' => 3, 'slug' => 'source'], 'app_instance' => $instance, 'app_instances' => [$instance], 'status' => 'completed', 'source_count' => 1, 'completed_count' => 1])],
            'mutation' => RegisterAppInstanceRequest::class, 'body' => ['source_path' => '/work/source'],
        ],
    };

    return $case;
}

function run_instance_source_consent(array $case, array $keys, bool $pty = true): array
{
    $directory = sys_get_temp_dir().'/orbit-instance-consent-'.Str::uuid();
    new Filesystem()->makeDirectory($directory);
    $configuration = [...$case, 'home' => $directory.'/home', 'trace' => $directory.'/requests.jsonl',
        'keys' => array_map(base64_encode(...), $keys)];
    file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    $fixtures = __DIR__.'/../../Fixtures/Console/';
    $argv = [PHP_BINARY, $fixtures.'command-consent.php', $directory.'/case.json'];
    try {
        $process = new Process($pty ? ['python3', $fixtures.'command-consent-pty.py', ...$argv] : $argv, env: ['PAO_DISABLE' => '1']);
        $process->setTimeout(15);
        $process->run();
        if ($pty) {
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $result['output'] = base64_decode($result['raw']);
        } else {
            $result = ['status' => $process->getExitCode(), 'output' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
        }
        $result['requests'] = is_file($configuration['trace']) ? array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($configuration['trace'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        ) : [];

        return $result;
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
}

it('requires default-No native consent and restores the terminal before returning', function (string $family, array $keys, bool $accepted): void {
    $case = instance_source_consent_case($family);
    $case['arguments'][] = '--no-ansi';
    $result = run_instance_source_consent($case, $keys);
    expect($result['status'])->toBe($accepted ? 0 : 1)->and($result['prompt_seen'])->toBeTrue()
        ->and($result['keys_remaining'])->toBe(0)->and($result['restored'])->toBeTrue()
        ->and($result['output'])->toContain($case['prompt'])->not->toContain("\e[")
        ->and($result['requests'])->toHaveCount($case['reads'] + ($accepted ? 1 : 0));
    if ($accepted) {
        $request = $result['requests'][array_key_last($result['requests'])];
        expect($request['class'])->toBe($case['mutation'])->and($request['body'])->toBe($case['body']);
    } else {
        expect($result['output'])->toContain('cancelled.');
    }
})->with(['remove', 'step', 'transfer', 'register'])->with([
    'default No' => [["\r"], false], 'Yes' => [['y', "\r"], true],
    'Ctrl-C' => [["\x03"], false], 'EOF' => [["\x04"], false],
]);

it('names the original source when confirming a transfer retry after cutover', function (bool $accepted): void {
    $case = instance_source_consent_case('transfer');
    $case['arguments'][] = '--no-ansi';
    $case['prompt'] = 'Retry transfer of App instance [source] (#11) from Node #2 to Node [destination] (#8) with downtime and deletion of the old placement?';
    $case['replies'][0]['body']['data']['node_id'] = 8;
    $case['replies'][0]['body']['data']['transfer'] = [
        'source_node_id' => 2, 'destination_node_id' => 8,
        'cutover_completed' => true, 'cleanup_completed' => false,
    ];

    $result = run_instance_source_consent($case, $accepted ? ['y', "\r"] : ["\r"]);

    expect($result['status'])->toBe($accepted ? 0 : 1)
        ->and($result['prompt_seen'])->toBeTrue()
        ->and($result['restored'])->toBeTrue()
        ->and($result['output'])->toContain($case['prompt'])
        ->and($result['requests'])->toHaveCount($accepted ? 3 : 2);
    if ($accepted) {
        expect($result['requests'][2]['class'])->toBe(TransferAppInstanceRequest::class)
            ->and($result['requests'][2]['body'])->toBe(['node_id' => 8]);
    }
})->with([false, true]);

it('leaves changed transfer retry options to Gateway identity validation', function (string $option, string $field, string $value): void {
    $case = instance_source_consent_case('transfer');
    $case['arguments'] = [...$case['arguments'], '--no-ansi', $option.'='.$value];
    $case['prompt'] = 'Retry transfer of App instance [source] (#11) from Node #2 to Node [destination] (#8) with downtime and deletion of the old placement?';
    $case['replies'][0]['body']['data']['node_id'] = 8;
    $case['replies'][0]['body']['data']['transfer'] = [
        'source_node_id' => 2, 'destination_node_id' => 8,
        'cutover_completed' => true, 'cleanup_completed' => false,
    ];
    $message = 'Only the identical transfer request can resume this AppInstance.';
    $case['replies'][2] = ['class' => TransferAppInstanceRequest::class, 'status' => 409,
        'body' => ['error' => ['code' => 'instance.transfer_retry_conflict', 'message' => $message]]];

    $result = run_instance_source_consent($case, ['y', "\r"]);

    expect($result['status'])->toBe(1)
        ->and($result['prompt_seen'])->toBeTrue()
        ->and($result['restored'])->toBeTrue()
        ->and($result['output'])->toContain($case['prompt'], $message)
        ->and($result['requests'])->toHaveCount(3)
        ->and($result['requests'][2]['body'])->toBe(['node_id' => 8, $field => $value]);
})->with([
    ['--name', 'name', 'other'],
    ['--sqlite-source-path', 'sqlite_source_path', '/work/other.sqlite'],
]);

it('keeps JSON and noninteractive mode separate from explicit consent', function (string $family, bool $json, bool $consent): void {
    $case = instance_source_consent_case($family);
    $case['arguments'] = [...$case['arguments'], '--ansi', ...($json ? ['--json'] : []), ...($consent ? [$case['option']] : [])];
    if ($consent) {
        $case['replies'] = array_slice($case['replies'], $case['reads']);
    }
    $result = run_instance_source_consent($case, [], pty: false);
    expect($result['status'])->toBe($consent ? 0 : 1)->and($result['stderr'])->toBe('')
        ->and($result['output'])->not->toContain("\e[")
        ->and($result['requests'])->toHaveCount($consent ? 1 : $case['reads']);
    if ($json) {
        $payload = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
        if (! $consent) {
            expect($payload['error']['code'])->toBe($case['code']);
        }
    }
    if ($consent) {
        expect($result['requests'][0]['class'])->toBe($case['mutation'])->and($result['requests'][0]['body'])->toBe($case['body']);
    }
})->with(['remove', 'step', 'transfer', 'register'])->with([false, true])->with([false, true]);

it('does not treat the removal source-safety override as consent', function (): void {
    $case = instance_source_consent_case('remove');
    $case['arguments'] = [...$case['arguments'], '--force', '--json'];
    $result = run_instance_source_consent($case, [], pty: false);
    expect($result['status'])->toBe(1)->and($result['requests'])->toHaveCount(1);
    expect(json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('input.confirmation_required');
});

it('rejects invalid supplied registration fields before consent or mutation', function (string $option, string $value, string $field): void {
    $case = instance_source_consent_case('register');
    $case['arguments'] = [...$case['arguments'], $option.'='.$value, '--yes', '--json'];
    $result = run_instance_source_consent($case, [], pty: false);
    expect($result['status'])->toBe(1)->and($result['requests'])->toBe([]);
    $error = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR)['error'];
    expect($error['code'])->toBe('validation.failed')->and($error['message'])->toBe('The request data is invalid.')
        ->and($error['details'])->toBe([$field => [$field === 'default_branch'
            ? 'The default branch is not a valid Git branch name.'
            : 'The root must be a normalized relative web path.']])->and($error['request_id'])->toBeNull();
})->with([
    ['--default-branch', 'bad branch', 'default_branch'],
    ['--default-branch', 'HEAD', 'default_branch'],
    ['--root', '../public', 'root'],
    ['--root', '/public', 'root'],
]);

it('retries invalid unresolved registration values before asking for ownership consent', function (): void {
    $case = instance_source_consent_case('register');
    $case['registration_facts']['defaultBranch'] = null;
    $case['registration_facts']['root'] = null;
    $case['prompt'] = 'Default branch';
    $case['arguments'][] = '--no-ansi';
    $result = run_instance_source_consent($case, ['..', "\r", "\x7f\x7f", 'release/next', "\r", '../web', "\r", str_repeat("\x7f", 6), 'web/public', "\r", 'y', "\r"]);
    expect($result['status'])->toBe(0)->and($result['restored'])->toBeTrue()->and($result['keys_remaining'])->toBe(0)
        ->and($result['requests'])->toHaveCount(1)->and($result['requests'][0]['body'])->toBe([
            'source_path' => '/work/source', 'default_branch' => 'release/next', 'root' => 'web/public',
        ])->and($result['output'])->toContain('Enter a valid Git branch name.', 'Enter a normalized relative web path.', 'Transfer source [/work/source]');
});

it('preserves the Gateway deployment-eligibility refusal before deploy-step consent', function (): void {
    $case = instance_source_consent_case('step');
    $case['replies'][1] = ['class' => ListInstanceDeployStepsRequest::class, 'status' => 409, 'body' => ['error' => [
        'code' => 'deployment_config.unavailable', 'message' => 'Deployment configuration is unavailable.',
    ]]];
    $case['arguments'][] = '--json';
    $result = run_instance_source_consent($case, [], pty: false);
    expect($result['status'])->toBe(1)->and($result['requests'])->toHaveCount(2)
        ->and(json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe('deployment_config.unavailable');
});

it('never takes consent from JSON even when stdin is a real terminal', function (string $family): void {
    $case = instance_source_consent_case($family);
    $case['arguments'][] = '--json';
    $result = run_instance_source_consent($case, []);
    expect($result['status'])->toBe(1)->and($result['prompt_seen'])->toBeFalse()->and($result['restored'])->toBeTrue()
        ->and($result['requests'])->toHaveCount($case['reads'])->and($result['output'])->not->toContain("\e[");
    expect(json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR)['error']['code'])->toBe($case['code']);
})->with(['remove', 'step', 'transfer', 'register']);
