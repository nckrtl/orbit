<?php

declare(strict_types=1);

use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\ComposerCheckEvidence;
use App\Domain\Tasks\TaskThreadRole;
use App\Infrastructure\Tasks\Pi\PiDriver;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PI_TOKEN = 'pi-node-token-with-more-than-32-characters';
const PI_BASE = 'http://10.44.0.9:3774';

/** @param array<string, mixed> $settings */
function pi_node(array $settings = ['pi' => ['token' => PI_TOKEN]]): Node
{
    return Node::query()->create([
        'name' => 'pi-node', 'platform' => 'linux', 'status' => 'active',
        'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'settings' => $settings,
    ]);
}

function pi_workspace(Node $node): AppInstance
{
    $app = OrbitApp::query()->create(['name' => 'pi', 'slug' => 'pi', 'repository_url' => 'git@example.test:pi.git', 'default_branch' => 'main']);

    return AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-1', 'checkout_path' => '/srv/task-1', 'status' => 'source_resolved']);
}

function pi_thread(Node $node, string $externalId = 'session-1'): AgentThread
{
    $workspace = pi_workspace($node);
    $group = TaskGroup::query()->create(['app_id' => $workspace->app_id, 'agent_driver' => 'pi', 'title' => 'Feature', 'brief' => 'Brief', 'status' => 'running']);

    return AgentThread::query()->create([
        'task_group_id' => $group->id,
        'driver' => 'pi', 'runtime_key' => 'node:'.$node->id, 'external_id' => $externalId, 'node_id' => $node->id,
        'role' => 'implementer', 'model' => 'gpt-5.6-luna', 'effort' => 'low',
    ]);
}

function pi_driver(): PiDriver
{
    return app(PiDriver::class);
}

/**
 * @param  list<array<string, mixed>>  $entries
 * @return array<string, mixed>
 */
function pi_snapshot(string $state, array $entries = [], ?string $error = null, string $id = 'session-1'): array
{
    return [
        'kind' => 'snapshot', 'sequence' => 7,
        'session' => ['id' => $id, 'cwd' => '/srv/task-1', 'model' => 'openai-codex/gpt-5.6-luna', 'thinkingLevel' => 'low'],
        'state' => $state, 'error' => $error, 'turnId' => 'turn-key-1', 'entries' => $entries,
        'usage' => ['input' => 100, 'output' => 20, 'cacheRead' => 0, 'cacheWrite' => 0, 'total' => 120],
    ];
}

/** @return list<array<string, mixed>> */
function pi_check_transcript(string $checkResult, bool $failed = false): array
{
    return [
        ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'user', 'content' => 'Implement it']],
        ['id' => 'e2', 'timestamp' => '2026-09-22T10:00:01.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
            ['type' => 'text', 'text' => 'Running the checks.'],
            ['type' => 'toolCall', 'id' => 'call-1', 'name' => 'bash', 'arguments' => ['command' => 'composer check']],
        ]]],
        ['id' => 'e3', 'timestamp' => '2026-09-22T10:00:02.000Z', 'message' => [
            'role' => 'toolResult', 'toolCallId' => 'call-1', 'toolName' => 'bash', 'isError' => $failed,
            'content' => [['type' => 'text', 'text' => $checkResult]],
        ]],
        ['id' => 'e4', 'timestamp' => '2026-09-22T10:00:03.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'stop', 'content' => [['type' => 'text', 'text' => 'Done.']]]],
    ];
}

describe('registration and placement', function (): void {
    it('is registered as the pi driver', function (): void {
        expect(app(AgentDriverRegistry::class)->get('pi'))->toBeInstanceOf(PiDriver::class);
    });

    it('allows a Node only while its pi-server Process is recorded as running', function (): void {
        $node = pi_node();
        expect(pi_driver()->allows($node))->toBeFalse();

        $process = $node->processes()->create([
            'name' => 'pi-server', 'runtime' => ProcessRuntime::Systemd, 'working_directory' => '/home/orbit',
            'runtime_config' => ['command' => ['/home/orbit/.local/bin/pi-server']], 'restart_policy' => 'always',
            'keep_alive' => true, 'desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Active,
        ]);
        expect(pi_driver()->allows($node))->toBeTrue();

        $process->update(['desired_state' => DesiredProcessState::Stopped]);
        expect(pi_driver()->allows($node))->toBeFalse();
    });
});

describe('create and send', function (): void {
    it('creates a session in the workspace and starts the opening turn', function (): void {
        Http::fake([PI_BASE.'/sessions' => Http::response(['id' => 'x'], 201), PI_BASE.'/sessions/*/messages' => Http::response(['duplicate' => false], 202)]);
        $node = pi_node();

        $id = pi_driver()->create(new AgentThreadStart($node, pi_workspace($node), 'Task', 'Do the work', 'gpt-5.6-luna', 'low', TaskThreadRole::Implementer));

        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions'
            && $request->hasHeader('Authorization', 'Bearer '.PI_TOKEN)
            && $request['id'] === $id && $request['cwd'] === '/srv/task-1'
            && $request['model'] === 'openai-codex/gpt-5.6-luna' && $request['thinkingLevel'] === 'low');
        Http::assertSent(fn (Request $request): bool => $request->url() === PI_BASE.'/sessions/'.$id.'/messages'
            && $request['text'] === 'Do the work' && is_string($request['key']));
    });

    it('retries a refused send once with the same key', function (): void {
        Http::fake([PI_BASE.'/sessions/*/messages' => Http::sequence()->push(['error' => ['code' => 'internal_error', 'message' => 'x']], 500)->push(['duplicate' => false], 202)]);
        $node = pi_node();

        pi_driver()->send(pi_thread($node), 'Continue');

        $keys = collect(Http::recorded())->map(fn (array $pair): mixed => $pair[0]['key'])->all();
        expect($keys)->toHaveCount(2)->and($keys[0])->toBe($keys[1]);
    });

    it('names the server error when both attempts fail', function (): void {
        Http::fake([PI_BASE.'/sessions/*/messages' => Http::response(['error' => ['code' => 'turn_active', 'message' => 'Session is already working on a turn.']], 409)]);

        expect(fn () => pi_driver()->send(pi_thread(pi_node()), 'Continue'))
            ->toThrow(AgentDriverException::class, 'The Pi server refused the request (turn_active): Session is already working on a turn.');
    });

    it('refuses Claude models before calling the server', function (): void {
        Http::fake();
        $node = pi_node();

        expect(fn () => pi_driver()->create(new AgentThreadStart($node, pi_workspace($node), 'Task', 'Review', 'claude-opus-5', 'high', TaskThreadRole::Reviewer)))
            ->toThrow(AgentDriverException::class, 'Claude models run on the T3 driver, not on Pi.');
        Http::assertNothingSent();
    });

    it('refuses a Node without a Pi token', function (): void {
        Http::fake();

        expect(fn () => pi_driver()->send(pi_thread(pi_node(['pi' => []])), 'Continue'))
            ->toThrow(AgentDriverException::class, 'The Node has no Pi server token configured.');
        Http::assertNothingSent();
    });

    it('reports an unreachable server as unavailable', function (): void {
        Http::fake(fn () => throw new ConnectionException('refused'));

        expect(fn () => pi_driver()->interrupt(pi_thread(pi_node())))->toThrow(AgentDriverException::class, 'The Pi server is unavailable.');
    });

    it('does not support input requests', function (): void {
        expect(fn () => pi_driver()->respond(pi_thread(pi_node()), new AgentInputRequest('r', 'question'), []))
            ->toThrow(AgentDriverException::class, 'The agent driver does not support input requests on Pi threads.');
    });
});

describe('observe', function (): void {
    it('maps a completed turn to done with entries, tokens, and turn identity', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1' => Http::response(pi_snapshot('done', pi_check_transcript("All checks passed\n")))]);

        $observation = pi_driver()->observe(pi_thread(pi_node()));

        expect($observation->state)->toBe(AgentThreadState::Done)
            ->and($observation->tokens)->toBe(120)
            ->and($observation->turnId)->toBe('turn-key-1')
            ->and($observation->cursor)->toBe('7')
            ->and(array_column($observation->entries, 'kind'))->toBe(['message', 'message', 'activity', 'message'])
            ->and($observation->entries[2])->toMatchArray(['label' => 'bash', 'text' => "All checks passed\n\n$ composer check\nexit code 0"]);
    });

    it('gives the scheduler a passing composer check from a successful bash result', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1' => Http::response(pi_snapshot('done', pi_check_transcript('ok')))]);

        $evidence = ComposerCheckEvidence::fromMessages(pi_driver()->observe(pi_thread(pi_node()))->entries);

        expect($evidence->invoked)->toBeTrue()->and($evidence->passed)->toBeTrue()->and($evidence->current)->toBeTrue();
    });

    it('gives the scheduler the exit code of a failed bash result', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1' => Http::response(pi_snapshot('done', pi_check_transcript("PHPStan found 2 errors\n\nCommand exited with code 2", failed: true)))]);

        $observation = pi_driver()->observe(pi_thread(pi_node()));
        $evidence = ComposerCheckEvidence::fromMessages($observation->entries);

        expect($observation->entries[2]['text'])->toEndWith("$ composer check\nexit code 2")
            ->and($evidence->invoked)->toBeTrue()->and($evidence->passed)->toBeFalse();
    });

    it('marks a check stale after a later edit', function (): void {
        $entries = [...pi_check_transcript('ok'),
            ['id' => 'e5', 'timestamp' => '2026-09-22T10:00:04.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
                ['type' => 'toolCall', 'id' => 'call-2', 'name' => 'edit', 'arguments' => ['path' => 'app/Service.php']],
            ]]],
            ['id' => 'e6', 'timestamp' => '2026-09-22T10:00:05.000Z', 'message' => ['role' => 'toolResult', 'toolCallId' => 'call-2', 'toolName' => 'edit', 'isError' => false, 'content' => [['type' => 'text', 'text' => 'Replaced 1 block.']]]],
        ];
        Http::fake([PI_BASE.'/sessions/session-1' => Http::response(pi_snapshot('done', $entries))]);

        $observation = pi_driver()->observe(pi_thread(pi_node()));

        expect(array_last($observation->entries)['text'])->toBe('edit app/Service.php')
            ->and(ComposerCheckEvidence::fromMessages($observation->entries)->current)->toBeFalse();
    });

    it('keeps file contents out of non-bash activity', function (): void {
        $entries = [
            ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'toolUse', 'content' => [
                ['type' => 'toolCall', 'id' => 'call-1', 'name' => 'read', 'arguments' => ['path' => 'README.md']],
            ]]],
            ['id' => 'e2', 'timestamp' => '2026-09-22T10:00:01.000Z', 'message' => ['role' => 'toolResult', 'toolCallId' => 'call-1', 'toolName' => 'read', 'isError' => false, 'content' => [['type' => 'text', 'text' => 'Always write tests.']]]],
        ];
        Http::fake([PI_BASE.'/sessions/session-1' => Http::response(pi_snapshot('idle', $entries))]);

        expect(pi_driver()->observe(pi_thread(pi_node()))->entries)->toBe([
            ['id' => 'e2', 'kind' => 'activity', 'label' => 'read', 'text' => 'read README.md', 'at' => '2026-09-22T10:00:01.000Z'],
        ]);
    });

    it('carries the reported failure', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1' => Http::response(pi_snapshot('failed', error: 'quota exceeded'))]);

        $observation = pi_driver()->observe(pi_thread(pi_node()));

        expect($observation->state)->toBe(AgentThreadState::Failed)->and($observation->error)->toBe('quota exceeded');
    });

    it('rejects a snapshot for another session', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1' => Http::response(pi_snapshot('done', id: 'other'))]);

        expect(fn () => pi_driver()->observe(pi_thread(pi_node())))->toThrow(AgentDriverException::class, 'Agent observation identity does not match.');
    });

    it('redacts the Node token from transcript text', function (): void {
        Http::fake([PI_BASE.'/sessions/session-1' => Http::response(pi_snapshot('done', [
            ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'assistant', 'stopReason' => 'stop', 'content' => [['type' => 'text', 'text' => 'token '.PI_TOKEN]]]],
        ]))]);

        expect(pi_driver()->observe(pi_thread(pi_node()))->entries[0]['text'])->toBe('token [REDACTED]');
    });
});

describe('events', function (): void {
    it('streams a snapshot, then normalized entries and states, ignoring events before the snapshot', function (): void {
        $lines = [
            ['kind' => 'entry', 'sequence' => 1, 'entry' => ['id' => 'early', 'timestamp' => 't', 'message' => ['role' => 'user', 'content' => 'too early']]],
            pi_snapshot('idle'),
            ['kind' => 'state', 'sequence' => 8, 'state' => 'working', 'error' => null, 'turnId' => 'turn-key-2', 'usage' => ['total' => 120]],
            ['kind' => 'entry', 'sequence' => 9, 'entry' => ['id' => 'e1', 'timestamp' => '2026-09-22T10:00:00.000Z', 'message' => ['role' => 'user', 'content' => 'Go']]],
            ['kind' => 'heartbeat'],
            ['kind' => 'state', 'sequence' => 10, 'state' => 'done', 'error' => null, 'turnId' => 'turn-key-2', 'usage' => ['total' => 300]],
        ];
        Http::fake([PI_BASE.'/sessions/session-1/stream' => Http::response(implode("\n", array_map(json_encode(...), $lines))."\n")]);
        $thread = pi_thread(pi_node());

        $events = iterator_to_array(pi_driver()->events($thread, null), false);

        expect(array_map(fn ($event): string => $event->kind, $events))->toBe(['snapshot', 'state', 'entry', 'heartbeat', 'state'])
            ->and($events[0]->cursor)->toBe('7')
            ->and($events[1]->data)->toMatchArray(['state' => 'working'])
            ->and($events[2]->data['entry'])->toMatchArray(['id' => 'e1', 'kind' => 'message', 'label' => 'user', 'text' => 'Go'])
            ->and($events[4]->data)->toMatchArray(['state' => 'done', 'tokens' => 300])
            ->and($events[4]->cursor)->toBe('10');
    });

    it('rejects a malformed cursor', function (): void {
        expect(fn () => iterator_to_array(pi_driver()->events(pi_thread(pi_node()), 'abc')))->toThrow(AgentDriverException::class, 'Invalid agent stream cursor.');
    });
});
