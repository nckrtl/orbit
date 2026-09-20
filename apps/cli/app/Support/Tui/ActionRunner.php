<?php

declare(strict_types=1);

namespace App\Support\Tui;

use Closure;
use Orbit\Sdk\Requests\DatabaseConnections\DestroyDatabaseConnectionRequest;
use Orbit\Sdk\Requests\Doctor\RunDoctorRequest;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Requests\Processes\RestartProcessRequest;
use Orbit\Sdk\Requests\Processes\StartProcessRequest;
use Orbit\Sdk\Requests\Processes\StopProcessRequest;
use Orbit\Sdk\Requests\Schedules\EnableScheduleRequest;
use Orbit\Sdk\Requests\Schedules\RunScheduleRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
use Orbit\Sdk\Responses\Doctor\DoctorReportResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;

/**
 * The record actions menu (`a` or right-click) offers, and what each one does. Actions run the
 * same SDK request the matching real command sends and refresh the row from the response, or
 * from the next realtime event. An action that has no request yet leaves the TUI: it names the
 * command to run and does not fabricate a result. See the class doc on TopCommand for which
 * actions fall in which bucket.
 */
final readonly class ActionRunner
{
    /** @param Closure(object, string): object $send Same shape as GatewayCommand::sendOrThrow(): throws GatewayApiException on failure. */
    public function __construct(
        private Closure $send,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, Action>
     */
    public function actionsFor(string $kind, array $row): array
    {
        return match ($kind) {
            'nodes' => [
                'doctor' => Action::real("Check node [{$row['name']}] for drift."),
                'ssh' => Action::leaves("orbit node:ssh {$row['name']}", 'No SDK request opens a shell; run this from a terminal.'),
            ],
            'apps' => [
                'show' => Action::leaves("orbit project:show {$row['id']}", 'Open the Project record instead of running this from the menu.'),
            ],
            'instances' => [
                'deploy' => Action::leaves("orbit instance:deploy {$row['app']['slug']}/{$row['name']}", 'A deploy streams for minutes; it is not run from inside the live screen.'),
                'logs' => Action::leaves("orbit instance:logs {$row['app']['slug']}/{$row['name']}", 'Tail the release log from a terminal.'),
                'profile' => Action::leaves("orbit instance:profile {$row['app']['slug']}/{$row['name']}", 'No SDK request captures a profile; run this from a terminal.'),
            ],
            'processes' => [
                'restart' => Action::real("Restart process [{$row['name']}]."),
                // A systemd process reports "active"/"inactive"; a Docker process reports
                // "running"/"exited" — never "running"/"stopped" (that vocabulary belongs to
                // desired_state). See State::processRuntimeIsActive().
                ...State::processRuntimeIsActive($row)
                    ? ['stop' => Action::real("Stop process [{$row['name']}].")]
                    : ['start' => Action::real("Start process [{$row['name']}].")],
            ],
            'schedules' => [
                'run now' => Action::real("Run schedule [{$row['name']}] now."),
                ...$row['desired_timer_state'] === 'enabled' ? [] : ['enable' => Action::real("Enable schedule [{$row['name']}].")],
            ],
            'databases' => [
                'destroy' => Action::destructive("Destroy the Database connection record [{$row['slug']}]? The physical database is not dropped."),
                'query' => Action::leaves("orbit database:query {$row['slug']}", 'The query console needs an interactive terminal.'),
            ],
            'firewall' => [
                'remove' => Action::destructive("Remove firewall rule [{$row['name']}] on node [{$row['node']}]?"),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{message: string, row: ?array<string, mixed>}
     */
    public function run(string $kind, string $label, array $row): array
    {
        return match (true) {
            $kind === 'nodes' && $label === 'doctor' => $this->doctor($row),
            $kind === 'processes' && $label === 'restart' => $this->processAction($row, new RestartProcessRequest($row['id']), 'restarted'),
            $kind === 'processes' && $label === 'stop' => $this->processAction($row, new StopProcessRequest($row['id']), 'stopped'),
            $kind === 'processes' && $label === 'start' => $this->processAction($row, new StartProcessRequest($row['id']), 'started'),
            $kind === 'schedules' && $label === 'run now' => $this->scheduleAction($row, new RunScheduleRequest($row['id']), 'ran'),
            $kind === 'schedules' && $label === 'enable' => $this->scheduleAction($row, new EnableScheduleRequest($row['id']), 'enabled'),
            $kind === 'databases' && $label === 'destroy' => $this->destroyDatabase($row),
            $kind === 'firewall' && $label === 'remove' => $this->removeFirewallRule($row),
            default => ['message' => "Nothing to run for {$kind}:{$label}.", 'row' => null],
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{message: string, row: null}
     */
    private function doctor(array $row): array
    {
        $report = ($this->send)(new RunDoctorRequest(nodeId: $row['id']), DoctorReportResponse::class);
        assert($report instanceof DoctorReportResponse);
        $drift = $report->summary['drift'];

        return ['message' => $drift === 0 ? "Node [{$row['name']}] has no drift." : "Node [{$row['name']}] has {$drift} drifted check(s); see doctor --node={$row['id']}.", 'row' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{message: string, row: array<string, mixed>}
     */
    private function processAction(array $row, object $request, string $pastTense): array
    {
        $response = ($this->send)($request, ProcessResponse::class);
        assert($response instanceof ProcessResponse);

        return ['message' => "Process [{$row['name']}] {$pastTense}.", 'row' => State::processRow($response)];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{message: string, row: array<string, mixed>}
     */
    private function scheduleAction(array $row, object $request, string $pastTense): array
    {
        $response = ($this->send)($request, ScheduleResponse::class);
        assert($response instanceof ScheduleResponse);

        return ['message' => "Schedule [{$row['name']}] {$pastTense}.", 'row' => State::scheduleRow($response)];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{message: string, row: null}
     */
    private function destroyDatabase(array $row): array
    {
        $response = ($this->send)(new DestroyDatabaseConnectionRequest($row['slug']), DatabaseConnectionResponse::class);
        assert($response instanceof DatabaseConnectionResponse);

        return ['message' => "Database connection [{$row['slug']}] destroyed.", 'row' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{message: string, row: null}
     */
    private function removeFirewallRule(array $row): array
    {
        $response = ($this->send)(new RemoveFirewallRuleRequest($row['node_id'], $row['name']), FirewallRuleResponse::class);
        assert($response instanceof FirewallRuleResponse);

        return ['message' => "Firewall rule [{$row['name']}] removed.", 'row' => null];
    }
}
