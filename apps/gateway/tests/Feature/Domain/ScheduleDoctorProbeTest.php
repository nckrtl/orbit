<?php

declare(strict_types=1);

use App\Actions\Doctor\ScheduleDoctorProbe;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyStatus;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\ScheduleInspectionData;
use App\Domain\Doctor\ScheduleStateInspector;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Schedule;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/srv/apps/docs',
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $this->schedule = Schedule::query()->create([
        'target_type' => AppInstance::class,
        'target_id' => $instance->id,
        'host_node_id' => $this->node->id,
        'name' => 'daily',
        'calendar' => 'sensitive-calendar-marker',
        'command' => 'secret-command --token=private',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Disabled,
        'status' => LifecycleStatus::Active,
    ]);
    $this->inspector = new ScheduleDoctorFakeInspector;
    $this->probe = new ScheduleDoctorProbe(
        $this->inspector,
        new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver),
    );
});

it('accepts a requested disabled and stopped AppInstance timer as healthy', function (): void {
    $report = $this->probe->inspect(new DoctorNodeContext($this->node, new NodeInspectionData(true, 'linux', 'x86_64', true)));

    expect($report->family)->toBe(DoctorFamily::Schedule)
        ->and($report->status)->toBe(DoctorFamilyStatus::Healthy)
        ->and($report->checked)->toBe(1)
        ->and($report->issues)->toBeEmpty();
});

it('reports only stable bounded codes and redacted values', function (): void {
    $this->inspector->inspection = new ScheduleInspectionData(true, false, false, false, false, false, false);
    $this->inspector->orphans = ['123e4567-e89b-42d3-a456-426614174099'];

    $report = $this->probe->inspect(new DoctorNodeContext($this->node, new NodeInspectionData(true, 'linux', 'x86_64', true)));
    $encoded = json_encode($report->toArray(), JSON_THROW_ON_ERROR);

    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))->toBe([
        'schedule.artifact_permissions_mismatch',
        'schedule.specification_mismatch',
        'schedule.timer_state_mismatch',
        'schedule.calendar_mismatch',
        'schedule.execution_context_mismatch',
        'schedule.completion_callback_mismatch',
        'schedule.orphan_artifact',
    ])->and($encoded)
        ->not->toContain('secret-command')
        ->not->toContain('/srv/apps/docs')
        ->not->toContain('private')
        ->not->toContain('sensitive-calendar-marker');
});

it('collapses unreachable host inspection to one family issue', function (): void {
    $report = $this->probe->inspect(new DoctorNodeContext($this->node, new NodeInspectionData(false, null, null, null)));

    expect($report->status)->toBe(DoctorFamilyStatus::Unverifiable)
        ->and($report->issues)->toHaveCount(1)
        ->and($report->issues[0]->code)->toBe('schedule.node_unreachable')
        ->and($this->inspector->inspections)->toBe(0);
});

final class ScheduleDoctorFakeInspector implements ScheduleStateInspector
{
    public ScheduleInspectionData $inspection;

    /** @var list<string> */
    public array $orphans = [];

    public int $inspections = 0;

    public function __construct()
    {
        $this->inspection = new ScheduleInspectionData(true, true, true, true, true, true, true);
    }

    public function inspect(Schedule $schedule): ScheduleInspectionData
    {
        $this->inspections++;

        return $this->inspection;
    }

    public function orphanIds(Node $node, array $knownIds): array
    {
        return $this->orphans;
    }
}
