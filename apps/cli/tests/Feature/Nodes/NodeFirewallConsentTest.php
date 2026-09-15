<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeAccessRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Symfony\Component\Process\Process;

function node_firewall_reply(string $class, array $data): array
{
    return ['class' => $class, 'body' => ['data' => $data,
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']]];
}

function node_firewall_consent_case(string $family, bool $purge = false): array
{
    $node = ['id' => 7, 'name' => 'worker', 'status' => 'active', 'roles' => []];
    $rule = ['id' => 4, 'node_id' => 7, 'node' => 'worker', 'name' => 'web', 'action' => 'allow',
        'source' => 'any', 'protocol' => 'tcp', 'port' => '8080', 'status' => 'active', 'backend_status' => 'absent'];

    return match ($family) {
        'node' => [
            'arguments' => ['node:remove', '7'],
            'prompt' => 'Remove Node [worker] (#7) from the Gateway?',
            'reads' => 1,
            'mutation' => RemoveNodeRequest::class,
            'mutation_body' => ['force' => true, 'offline' => false],
            'replies' => [node_firewall_reply(ShowNodeRequest::class, $node),
                node_firewall_reply(RemoveNodeRequest::class, [...$node, 'removed' => true])],
        ],
        'access' => [
            'arguments' => ['node:access:remove', '7', '8'],
            'prompt' => 'Remove access from node #7 to node #8?',
            'reads' => 2,
            'mutation' => RemoveNodeAccessRequest::class,
            'mutation_body' => [],
            'replies' => [node_firewall_reply(ShowNodeRequest::class, $node),
                node_firewall_reply(ShowNodeRequest::class, [...$node, 'id' => 8, 'name' => 'serving']),
                node_firewall_reply(RemoveNodeAccessRequest::class, [
                    'consumer_node' => ['id' => 7, 'name' => 'worker'],
                    'serving_node' => ['id' => 8, 'name' => 'serving'],
                    'removed' => true, 'already_absent' => false, 'self_lockout' => false,
                ])],
        ],
        'role' => [
            'arguments' => ['node:role:remove', '7', 'app-dev', ...($purge ? ['--purge-data'] : [])],
            'prompt' => "Remove role 'app-dev' from node #7".($purge ? ' and purge supported role-owned data' : '').'?',
            'reads' => 1,
            'mutation' => RemoveNodeRoleRequest::class,
            'mutation_body' => ['force' => true, 'purge_data' => $purge, 'offline' => false],
            'replies' => [
                ['class' => RemoveNodeRoleRequest::class, 'status' => 422, 'body' => ['error' => [
                    'code' => 'validation.failed', 'message' => 'Use --force to remove this node role.',
                    'details' => ['field' => 'force', 'reason' => 'destructive_consent_required',
                        'dependents' => ['1 development instance record', '1 workspace record']],
                ]]],
                node_firewall_reply(RemoveNodeRoleRequest::class, ['node_id' => 7, 'node_name' => 'worker',
                    'role' => 'app-dev', 'assignment' => null, 'removed' => true]),
            ],
        ],
        'firewall' => [
            'arguments' => ['firewall:remove', 'web', '--node=7'],
            'prompt' => 'Remove firewall rule [web] from Node #7?',
            'reads' => 1,
            'mutation' => RemoveFirewallRuleRequest::class,
            'mutation_body' => [],
            'replies' => [node_firewall_reply(ListFirewallRulesRequest::class, [$rule]),
                node_firewall_reply(RemoveFirewallRuleRequest::class, $rule)],
        ],
    };
}

function run_node_firewall_fixture(array $case, array $keys, bool $pty = true): array
{
    $directory = sys_get_temp_dir().'/orbit-consent-'.Str::uuid();
    new Filesystem()->makeDirectory($directory);
    $configuration = [...$case, 'home' => $directory.'/home', 'trace' => $directory.'/requests.jsonl',
        'keys' => array_map(base64_encode(...), $keys)];
    file_put_contents($directory.'/case.json', json_encode($configuration, JSON_THROW_ON_ERROR));
    $fixtures = __DIR__.'/../../Fixtures/Console/';
    $argv = [PHP_BINARY, $fixtures.'command-consent.php', $directory.'/case.json'];

    try {
        $process = new Process($pty ? ['python3', $fixtures.'command-consent-pty.py', ...$argv] : $argv,
            env: ['PAO_DISABLE' => '1']);
        $process->setTimeout(15);
        $process->run();

        if ($pty) {
            expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $result['output'] = base64_decode($result['raw']);
        } else {
            $result = ['status' => $process->getExitCode(), 'output' => $process->getOutput(),
                'stderr' => $process->getErrorOutput()];
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

describe('Node and firewall native consent', function (): void {
    it('admits only affirmative consent and restores the terminal', function (string $family, array $keys, bool $accepted): void {
        $case = node_firewall_consent_case($family, purge: $family === 'role');
        $case['arguments'][] = '--no-ansi';
        $result = run_node_firewall_fixture($case, $keys);
        expect($result['status'])->toBe($accepted ? 0 : 1)
            ->and($result['prompt_seen'])->toBeTrue()
            ->and($result['keys_remaining'])->toBe(0)
            ->and($result['restored'])->toBeTrue()
            ->and($result['output'])->not->toContain("\e[")
            ->and($result['requests'])->toHaveCount($case['reads'] + ($accepted ? 1 : 0));

        if ($family === 'role') {
            expect($result['requests'][0]['body'])->toBe(['force' => false, 'purge_data' => false, 'offline' => false]);
            expect($result['output'])->toContain('Dependent resources:', '1 development instance record', '1 workspace record');
        }

        if ($accepted) {
            $mutation = $result['requests'][array_key_last($result['requests'])];
            expect($mutation['class'])->toBe($case['mutation'])->and($mutation['body'])->toBe($case['mutation_body']);
        } else {
            expect($result['output'])->toContain('cancelled.');
        }
    })->with(['node', 'access', 'role', 'firewall'])->with([
        'default No' => [["\r"], false], 'No' => [['n', "\r"], false],
        'Yes' => [['y', "\r"], true], 'Ctrl-C' => [["\x03"], false], 'EOF' => [["\x04"], false],
    ]);

    it('requires explicit firewall consent in human and machine automation', function (bool $json, bool $yes): void {
        $case = node_firewall_consent_case('firewall');
        $case['arguments'] = [...$case['arguments'], '--ansi', ...($json ? ['--json'] : []), ...($yes ? ['--yes'] : [])];
        if ($yes) {
            array_shift($case['replies']);
        }
        $result = run_node_firewall_fixture($case, [], pty: false);
        expect($result['status'])->toBe($yes ? 0 : 1)->and($result['stderr'])->toBe('')
            ->and($result['output'])->not->toContain("\e[")
            ->and($result['requests'])->toHaveCount(1)
            ->and($result['requests'][0]['class'])->toBe($yes ? RemoveFirewallRuleRequest::class : ListFirewallRulesRequest::class);
        if ($json) {
            $payload = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
            expect($yes ? $payload['backend_status'] : $payload['error']['code'])->toBe($yes ? 'absent' : 'input.confirmation_required');
        } elseif (! $yes) {
            expect($result['output'])->toContain('Supply --yes');
        }
    })->with([false, true])->with([false, true]);
});

it('never asks for consent or mutates from JSON in a terminal', function (string $family, string $code): void {
    $case = node_firewall_consent_case($family);
    $case['arguments'][] = '--json';
    $result = run_node_firewall_fixture($case, []);
    expect($result['status'])->toBe(1)->and($result['prompt_seen'])->toBeFalse()
        ->and($result['output'])->not->toContain("\e[")
        ->and($result['requests'])->toHaveCount($case['reads']);
    $payload = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
    expect($payload['error']['code'])->toBe($code);
})->with([
    ['node', 'node.confirmation_required'], ['access', 'node_access.confirmation_required'],
    ['role', 'validation.failed'], ['firewall', 'input.confirmation_required'],
]);

it('rejects a transport response that does not establish the requested removal', function (string $family, bool $json): void {
    $case = node_firewall_consent_case($family);
    $case['arguments'] = [...$case['arguments'], $family === 'firewall' ? '--yes' : '--force', ...($json ? ['--json'] : [])];
    if ($family !== 'node') {
        array_shift($case['replies']);
    }
    $last = array_key_last($case['replies']);
    $data = &$case['replies'][$last]['body']['data'];
    if ($family === 'firewall') {
        $data['backend_status'] = 'inactive';
    } else {
        $data['removed'] = false;
        if ($family === 'role') {
            $data['assignment'] = ['id' => 2, 'role' => 'app-dev', 'status' => 'active', 'failed_step' => null, 'error_code' => null];
        }
    }
    $result = run_node_firewall_fixture($case, [], pty: false);
    expect($result['status'])->toBe(1)->and($result['stderr'])->toBe('')
        ->and($result['output'])->not->toContain('Removed Node.', 'Removed Node role.', 'Removed firewall rule.', '[web] removed.');
    if ($json) {
        $payload = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
        expect($payload['error']['code'])->toBe('gateway.invalid_response')
            ->and($payload['error']['request_id'])->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    } else {
        expect($result['output'])->toContain('Operation failed.', 'does not confirm');
    }
})->with(['node', 'role', 'firewall'])->with([false, true]);

it('keeps authorization and missing-rule failures ahead of firewall consent', function (bool $forbidden): void {
    $case = node_firewall_consent_case('firewall');
    $case['arguments'][] = '--json';
    $case['replies'][0] = $forbidden
        ? ['class' => ListFirewallRulesRequest::class, 'status' => 403, 'body' => ['error' => [
            'code' => 'authorization.node_access_denied', 'message' => 'Node access is denied.', 'details' => [],
        ]]]
        : node_firewall_reply(ListFirewallRulesRequest::class, []);
    $result = run_node_firewall_fixture($case, [], pty: false);
    $payload = json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR);
    expect($result['status'])->toBe(1)->and($result['requests'])->toHaveCount(1)
        ->and($payload['error']['code'])->toBe($forbidden ? 'authorization.node_access_denied' : 'http.404')
        ->and($payload['error']['request_id'])->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
})->with([false, true]);
