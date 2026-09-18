<?php

declare(strict_types=1);

use App\Support\Tui\Action;
use App\Support\Tui\ActionRunner;
use Orbit\Sdk\Requests\Processes\RestartProcessRequest;
use Orbit\Sdk\Responses\Processes\ProcessResponse;

describe(ActionRunner::class, function (): void {
    it('offers stop for a running process and start for a stopped one', function (): void {
        $runner = new ActionRunner(fn (): never => throw new RuntimeException('not used'));

        $running = tui_test_state()->processes[0];
        $stopped = [...$running, 'runtime_status' => 'stopped'];

        expect(array_keys($runner->actionsFor('processes', $running)))->toBe(['restart', 'stop'])
            ->and(array_keys($runner->actionsFor('processes', $stopped)))->toBe(['restart', 'start']);
    });

    it('does not offer disable for a schedule, since the product has no such request', function (): void {
        $runner = new ActionRunner(fn (): never => throw new RuntimeException('not used'));
        $enabled = tui_test_state()->schedules[0];

        expect(array_keys($runner->actionsFor('schedules', $enabled)))->toBe(['run now']);
    });

    it('marks node:ssh as a leaving action with no SDK request', function (): void {
        $runner = new ActionRunner(fn (): never => throw new RuntimeException('not used'));
        $actions = $runner->actionsFor('nodes', tui_test_state()->nodes[0]);

        expect($actions['ssh'])->toBeInstanceOf(Action::class)
            ->and($actions['ssh']->real)->toBeFalse()
            ->and($actions['ssh']->command)->toBe('orbit node:ssh beast');
    });

    it('runs the same request restart:process sends and returns the refreshed row', function (): void {
        $sent = null;
        $runner = new ActionRunner(function (object $request, string $class) use (&$sent): object {
            $sent = $request;

            expect($request)->toBeInstanceOf(RestartProcessRequest::class)
                ->and($class)->toBe(ProcessResponse::class);

            return ProcessResponse::fromGatewayData([
                'id' => 1,
                'target_type' => 'instance',
                'target_id' => 1,
                'name' => 'horizon',
                'runtime' => 'systemd',
                'working_directory' => '/srv/charlie-shop',
                'restart_policy' => 'always',
                'keep_alive' => true,
                'desired_state' => 'running',
                'status' => 'active',
                'runtime_status' => 'running',
                'failed_step' => null,
                'error_code' => null,
            ], '0198e15d-16c4-7855-8eb2-182b53ad28ba');
        });

        $result = $runner->run('processes', 'restart', tui_test_state()->processes[0]);

        expect($sent)->not->toBeNull()
            ->and($result['message'])->toBe('Process [horizon] restarted.')
            ->and($result['row']['runtime_status'])->toBe('running');
    });

    it('marks a destructive action so the caller confirms before running it', function (): void {
        $runner = new ActionRunner(fn (): never => throw new RuntimeException('not used'));
        $actions = $runner->actionsFor('databases', tui_test_state()->databases[0]);

        expect($actions['destroy']->destructive)->toBeTrue();
    });
});
