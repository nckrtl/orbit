<?php

declare(strict_types=1);

use App\Actions\Fleet\ConvergeFleetRecipeAction;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\RoleName;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Jobs\MaintainMachineRecipe;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakePhpBuildRunner;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\FleetTestSsh;
use Tests\Support\Fleet\RecordingReleaseAlertNotifier;
use Tests\Support\Fleet\ScriptedSshExecutor;
use Tests\Support\TaskVmSettingsBuilder;

final class NightlyRecipeDoctor implements FleetNodeVerification
{
    public array $visited = [];

    public ?int $failNode = null;

    public ?Closure $afterVerify = null;

    public function baseline(Node $node): array
    {
        throw new LogicException('Every recipe visit must pass Doctor.');
    }

    public function verify(Node $node, array $baseline, string $pinnedAgentVersion, array $tolerated = []): array
    {
        $this->visited[] = $node->id;
        $this->afterVerify?->__invoke();

        return ['passed' => $node->id !== $this->failNode, 'new_issues' => [], 'preexisting_issues' => [], 'agent_version' => $pinnedAgentVersion, 'presence' => 'matched'];
    }
}

/** @return array{TaskVmImageBuild, TaskVmImageBuild, NightlyRecipeDoctor, RecordingReleaseAlertNotifier} */
function nightlyRecipeFleet(bool $newerUbuntu = false): array
{
    test()->travelTo(new DateTimeImmutable('2026-10-15 01:00:00 UTC'));
    FleetFixtures::inPlace();
    FleetFixtures::adopt(FleetFixtures::Commit);
    FleetFixtures::runFrom(FleetFixtures::Commit);
    $host = FleetFixtures::node('nightly-host-one', [RoleName::AppDev]);
    $other = FleetFixtures::node('nightly-host-two', [RoleName::AppDev]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id), TaskVmSettingsBuilder::host($other->id)]]);
    $recipe = new RecipeLoader()->load();
    $directory = config('orbit.gateway_checkout').'/resources/machine-software';
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/recipe.json', $recipe->json);
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => $recipe->identity, 'generation' => 1]);
    $records = [];
    foreach ([$host, $other] as $node) {
        $records[] = TaskVmImageBuild::query()->create(['host_node_id' => $node->id, 'recipe_identity' => $recipe->identity, 'recipe_json' => $recipe->json,
            'recipe_generation' => 1, 'state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now(),
            'images' => ['base' => ['project' => 'orbit-tasks', 'alias' => 'base', 'fingerprint' => str_repeat('a', 64), 'type' => 'virtual-machine']]]);
    }
    $ssh = new ScriptedSshExecutor;
    $ssh->on('/^python3 - inspect /', static function (RemoteCommand $command) use ($newerUbuntu): CommandResult {
        $data = json_decode($command->arguments[3], true, flags: JSON_THROW_ON_ERROR);

        $tools = [];
        foreach ($data['tools'] as $name => $pin) {
            $newer = $newerUbuntu && $name === 'curl';
            $tools[$name] = ['source' => $pin['source'], 'version' => $pin['version'], 'installed' => $newer ? '8.18.0-1ubuntu2.8' : $pin['version'], 'changed' => false, 'status' => $newer ? 'newer_than_recipe' : 'current'];
        }

        return ScriptedSshExecutor::ok(json_encode($tools, JSON_THROW_ON_ERROR));
    });
    $ssh->on('/^sudo timeout .* python3 - apply /', ScriptedSshExecutor::ok(json_encode([
        'changed_packages' => $newerUbuntu ? [] : ['curl'], 'restarted_services' => [], 'changed_tools' => $newerUbuntu ? [] : ['curl'], 'healthy' => true,
        'newer_than_recipe' => $newerUbuntu ? ['curl' => ['installed' => '8.18.0-1ubuntu2.8', 'version' => $recipe->tools['curl']->version]] : [],
    ], JSON_THROW_ON_ERROR)));
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));
    app()->instance(RemoteProgramRunner::class, new FakePhpBuildRunner);
    app()->instance(NodeUpdateLock::class, new NodeUpdateLock(FleetTestSsh::shell($ssh)));
    $doctor = new NightlyRecipeDoctor;
    app()->instance(FleetNodeVerification::class, $doctor);
    $alerts = new RecordingReleaseAlertNotifier;
    app()->instance(ReleaseAlertNotifier::class, $alerts);

    return [$records[0], $records[1], $doctor, $alerts];
}

it('waits for both promoted current-generation hosts then visits one Node per tick in ADR order', function (): void {
    [$record, $other, $doctor, $alerts] = nightlyRecipeFleet();
    $gateway = FleetFixtures::node('nightly-gateway', [RoleName::Gateway]);
    $vpn = FleetFixtures::node('nightly-vpn', [RoleName::Vpn]);
    $prod = FleetFixtures::node('nightly-prod', [RoleName::AppProd]);
    $service = FleetFixtures::node('nightly-service', [RoleName::Metrics]);
    $dev = FleetFixtures::node('nightly-dev', [RoleName::AppDev]);
    $multi = FleetFixtures::node('nightly-multi', [RoleName::AppDev, RoleName::Vpn]);
    FleetFixtures::node('nicks-mac', [RoleName::AppDev], platform: 'macos');
    FleetFixtures::node('operator');
    FleetFixtures::taskVm();
    $other->update(['state' => TaskVmImageBuildState::Running, 'promoted_at' => null]);
    $action = app(ConvergeFleetRecipeAction::class);

    $action->execute();
    expect($doctor->visited)->toBe([]);
    $other->update(['state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now(), 'recipe_generation' => 0]);
    $action->execute();
    expect($doctor->visited)->toBe([]);
    $other->update(['recipe_generation' => 1]);
    $expected = [$record->host_node_id, $other->host_node_id, $dev->id, $service->id, $prod->id, $vpn->id, $multi->id, $gateway->id];
    foreach ($expected as $index => $id) {
        $action->execute();
        expect($doctor->visited)->toBe(array_slice($expected, 0, $index + 1));
    }
    $action->execute();
    $action->execute();

    expect($doctor->visited)->toBe($expected);
    expect($record->refresh()->fleet_convergence)->toMatchArray(['generation' => 1, 'status' => 'succeeded']);
    expect($record->fleet_convergence['nodes'][$prod->id]['changes']['changed_packages'])->toBe(['curl']);
    expect($alerts->alerts)->toBe([]);
    Artisan::call('task-vms:image-builds');
    expect(Artisan::output())->toContain('Fleet convergence:', 'changed_packages', 'succeeded');
});

it('skips Docker CE Nodes with the typed reason instead of halting fleet convergence', function (): void {
    [$record, $other, $doctor, $alerts] = nightlyRecipeFleet();
    $ssh = new ScriptedSshExecutor;
    $ssh->on('/^python3 - inspect /', ScriptedSshExecutor::fail(stdout: 'recipe-stage:docker-provider:docker-ce'));
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));
    app()->instance(NodeUpdateLock::class, new NodeUpdateLock(FleetTestSsh::shell($ssh)));
    $action = app(ConvergeFleetRecipeAction::class);
    $action->execute();
    $action->execute();
    $action->execute();

    expect($record->refresh()->fleet_convergence['status'])->toBe('succeeded')
        ->and($record->fleet_convergence['nodes'][$record->host_node_id]['reason'])->toBe('docker-provider:docker-ce')
        ->and($record->fleet_convergence['nodes'][$other->host_node_id]['status'])->toBe('skipped')
        ->and($doctor->visited)->toBe([])
        ->and($alerts->alerts)->toBe([])
        ->and($ssh->commands)->toHaveCount(2);
});

it('halts at a Doctor failure without retrying or touching later Nodes and alerts once', function (): void {
    [$record, $other, $doctor, $alerts] = nightlyRecipeFleet();
    $prod = FleetFixtures::node('nightly-untouched', [RoleName::AppProd]);
    $doctor->failNode = $other->host_node_id;
    $action = app(ConvergeFleetRecipeAction::class);

    $action->execute();
    $action->execute();
    $action->execute();

    expect($doctor->visited)->toBe([$record->host_node_id, $other->host_node_id]);
    expect($record->refresh()->fleet_convergence)->toMatchArray(['status' => 'halted', 'node_id' => $other->host_node_id]);
    expect($record->fleet_convergence['nodes'])->toHaveCount(1);
    expect($prod->refresh()->settings['machine_recipe'] ?? null)->toBeNull();
    expect($alerts->alerts)->toHaveCount(1);
    expect($alerts->alerts[0]->summary)->toContain('Doctor', 'No automatic rollback or retry');
});

it('halts on a failed image build before visiting any Node', function (): void {
    [$record, $other, $doctor, $alerts] = nightlyRecipeFleet();
    $record->update(['state' => TaskVmImageBuildState::Running, 'promoted_at' => null]);
    $other->update(['state' => TaskVmImageBuildState::Failed]);

    app(ConvergeFleetRecipeAction::class)->execute();
    app(ConvergeFleetRecipeAction::class)->execute();

    expect($doctor->visited)->toBe([]);
    expect($record->refresh()->fleet_convergence['status'])->toBe('halted');
    expect($alerts->alerts)->toHaveCount(1);
    expect($alerts->alerts[0]->summary)->toContain('Image build ['.$other->id.'] failed');
});

it('halts an interrupted visit and preserves its stopping point instead of retrying it', function (): void {
    [$record, $other, $doctor, $alerts] = nightlyRecipeFleet();
    $record->forceFill(['fleet_convergence' => ['generation' => 1, 'status' => 'visiting', 'node_id' => $other->host_node_id, 'nodes' => []]])->save();

    app(ConvergeFleetRecipeAction::class)->execute();

    expect($doctor->visited)->toBe([]);
    expect($record->refresh()->fleet_convergence)->toMatchArray(['status' => 'halted', 'node_id' => $other->host_node_id]);
    expect($alerts->alerts[0]->summary)->toContain('Interrupted visit');
});

it('does not overlap visits or advance an undeployed desired identity', function (): void {
    [$record, $other, $doctor] = nightlyRecipeFleet();
    $lock = Cache::lock('machine-recipe:fleet', 1700);
    $lock->get();

    app(ConvergeFleetRecipeAction::class)->execute();
    $lock->release();
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => 'sha256:'.str_repeat('f', 64)]);
    app(ConvergeFleetRecipeAction::class)->execute();

    expect($doctor->visited)->toBe([]);
    expect($record->refresh()->fleet_convergence)->toBeNull();
});

it('stops an old release and restarts progress for a new desired generation', function (): void {
    [$record, $other, $doctor] = nightlyRecipeFleet();
    $record->forceFill(['fleet_convergence' => ['generation' => 0, 'status' => 'halted', 'nodes' => []]])->save();
    app(ConvergeFleetRecipeAction::class)->execute();
    FleetFixtures::adopt(str_repeat('b', 40));
    app(ConvergeFleetRecipeAction::class)->execute();

    expect($doctor->visited)->toBe([$record->host_node_id]);
    expect($record->refresh()->fleet_convergence['generation'])->toBe(1);
});

it('puts the serving host last even without a gateway role', function (): void {
    [$record, $other, $doctor] = nightlyRecipeFleet();
    app(GatewayServingHost::class)->remember($record->hostNode);

    app(ConvergeFleetRecipeAction::class)->execute();

    expect($doctor->visited)->toBe([$other->host_node_id]);
});

it('schedules the date PR at Amsterdam 03:00 across DST and fleet visits each minute', function (string $utc): void {
    $this->travelTo(new DateTimeImmutable($utc));
    Artisan::call('schedule:list');
    $events = collect(app(Schedule::class)->events())->filter(fn ($event): bool => $event->description === MaintainMachineRecipe::class)->values();

    expect($events)->toHaveCount(2);
    expect($events[0]->expression)->toBe('0 3 * * *');
    expect($events[0]->timezone)->toBe('Europe/Amsterdam');
    expect($events[0]->isDue(app()))->toBeTrue();
    expect($events[1]->expression)->toBe('* * * * *');
})->with(['2026-07-15 01:00:00 UTC', '2026-12-15 02:00:00 UTC']);

it('converges app-dev-only Nodes mid-day but waits until 03:00 for app-dev plus database and production', function (): void {
    [$record, $other, $doctor, $alerts] = nightlyRecipeFleet();
    $gateway = FleetFixtures::node('window-gateway', [RoleName::Gateway]);
    $vpn = FleetFixtures::node('window-vpn', [RoleName::Vpn]);
    $prod = FleetFixtures::node('window-prod', [RoleName::AppProd]);
    $service = FleetFixtures::node('window-services', [RoleName::AppDev, RoleName::Database]);
    $this->travelTo(new DateTimeImmutable('2026-10-15 12:00:00 UTC'));
    $action = app(ConvergeFleetRecipeAction::class);

    for ($visit = 0; $visit < 5; $visit++) {
        $action->execute();
    }

    expect($doctor->visited)->toBe([$record->host_node_id, $other->host_node_id]);
    expect($record->refresh()->fleet_convergence)->toMatchArray(['status' => 'ready', 'node_id' => $service->id]);
    expect($service->refresh()->settings['machine_recipe'] ?? null)->toBeNull();
    expect($prod->refresh()->settings['machine_recipe'] ?? null)->toBeNull();
    expect($alerts->alerts)->toBe([]);

    $this->travelTo(new DateTimeImmutable('2026-10-16 01:00:00 UTC'));
    $action->execute();
    $action->execute();
    $action->execute();
    $action->execute();
    $action->execute();

    expect($doctor->visited)->toBe([$record->host_node_id, $other->host_node_id, $service->id, $prod->id, $vpn->id, $gateway->id]);
    expect($record->refresh()->fleet_convergence['status'])->toBe('succeeded');
});

it('starts shared services and production only inside the Amsterdam window including DST boundaries', function (string $utc, bool $allowed, RoleName $role): void {
    [$record, $other, $doctor, $alerts] = nightlyRecipeFleet();
    foreach ([$record->hostNode, $other->hostNode] as $node) {
        $node->update(['settings' => ['machine_recipe' => ['version' => $record->recipe_identity]]]);
    }
    $prod = FleetFixtures::node('window-boundary', [$role]);
    $this->travelTo(new DateTimeImmutable($utc));

    app(ConvergeFleetRecipeAction::class)->execute();

    expect($doctor->visited)->toBe($allowed ? [$prod->id] : []);
    expect($record->refresh()->fleet_convergence['status'])->toBe('ready');
    expect($alerts->alerts)->toBe([]);
})->with([
    'summer before 03' => ['2026-07-15 00:59:59 UTC', false],
    'summer at 03' => ['2026-07-15 01:00:00 UTC', true],
    'summer before 06' => ['2026-07-15 03:59:59 UTC', true],
    'summer at 06' => ['2026-07-15 04:00:00 UTC', false],
    'winter before 03' => ['2026-12-15 01:59:59 UTC', false],
    'winter at 03' => ['2026-12-15 02:00:00 UTC', true],
    'winter before 06' => ['2026-12-15 04:59:59 UTC', true],
    'winter at 06' => ['2026-12-15 05:00:00 UTC', false],
    'spring transition at 03' => ['2026-03-29 01:00:00 UTC', true],
    'spring transition at 06' => ['2026-03-29 04:00:00 UTC', false],
    'autumn repeated 02' => ['2026-10-25 01:30:00 UTC', false],
    'autumn transition at 03' => ['2026-10-25 02:00:00 UTC', true],
])->with([RoleName::Database, RoleName::AppProd]);

it('allows a running prod visit to finish past 06 but starts no next visit', function (): void {
    [$record, $other, $doctor] = nightlyRecipeFleet();
    foreach ([$record->hostNode, $other->hostNode] as $node) {
        $node->update(['settings' => ['machine_recipe' => ['version' => $record->recipe_identity]]]);
    }
    $prod = FleetFixtures::node('window-finishes', [RoleName::AppProd]);
    $vpn = FleetFixtures::node('window-next-night', [RoleName::Vpn]);
    $this->travelTo(new DateTimeImmutable('2026-10-15 03:59:59 UTC'));
    $doctor->afterVerify = fn () => $this->travelTo(new DateTimeImmutable('2026-10-15 04:01:00 UTC'));

    app(ConvergeFleetRecipeAction::class)->execute();
    app(ConvergeFleetRecipeAction::class)->execute();

    expect($doctor->visited)->toBe([$prod->id]);
    expect($prod->refresh()->settings['machine_recipe']['version'])->toBe($record->recipe_identity);
    expect($vpn->refresh()->settings['machine_recipe'] ?? null)->toBeNull();
    expect($record->refresh()->fleet_convergence)->toMatchArray(['status' => 'ready', 'node_id' => $vpn->id]);
});

it('visits a roleless CI runner with its recorded ci-runner profile in the overnight service tier', function (): void {
    [$record, $other, $doctor] = nightlyRecipeFleet();
    $prod = FleetFixtures::node('ci-window-prod', [RoleName::AppProd]);
    $sabre = FleetFixtures::node('ci-window-sabre');
    $sabre->update(['settings' => ['machine_recipe' => ['version' => 'sha256:previous', 'profile' => 'ci-runner']]]);
    FleetFixtures::node('ci-window-operator');
    $this->travelTo(new DateTimeImmutable('2026-10-15 12:00:00 UTC'));
    $action = app(ConvergeFleetRecipeAction::class);

    for ($visit = 0; $visit < 3; $visit++) {
        $action->execute();
    }
    expect($doctor->visited)->toBe([$record->host_node_id, $other->host_node_id])
        ->and($record->refresh()->fleet_convergence)->toMatchArray(['status' => 'ready', 'node_id' => $sabre->id]);

    $this->travelTo(new DateTimeImmutable('2026-10-16 01:00:00 UTC'));
    for ($visit = 0; $visit < 3; $visit++) {
        $action->execute();
    }

    expect($doctor->visited)->toBe([$record->host_node_id, $other->host_node_id, $sabre->id, $prod->id])
        ->and($sabre->refresh()->settings['machine_recipe']['profile'])->toBe('ci-runner')
        ->and($record->refresh()->fleet_convergence['status'])->toBe('succeeded');
});

it('records accepted newer Ubuntu security builds in nightly convergence without package changes', function (): void {
    [$record, $other, $doctor, $alerts] = nightlyRecipeFleet(newerUbuntu: true);

    app(ConvergeFleetRecipeAction::class)->execute();

    $changes = $record->refresh()->fleet_convergence['nodes'][$record->host_node_id]['changes'];
    expect($changes['changed_packages'])->toBe([])
        ->and($changes['newer_than_recipe']['curl'])->toBe(['installed' => '8.18.0-1ubuntu2.8', 'version' => '8.18.0-1ubuntu2.7'])
        ->and($alerts->alerts)->toBe([]);
});
