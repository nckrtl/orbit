<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One serial visit per minute; the existing build row retains the stopping point and change report. */
final readonly class ConvergeFleetRecipeAction
{
    public function __construct(
        private RecipeImageBuilds $builds,
        private TaskVmSettings $settings,
        private ConvergeNodeRecipeAction $converge,
        private ManagedNodeEligibility $eligibility,
        private GatewayServingHost $gateway,
        private FleetServingRelease $serving,
        private RecipeMaintenanceAlerts $alerts,
    ) {}

    public function execute(): void
    {
        Cache::lock('machine-recipe:fleet', 1700)->get(function (): void {
            if ($this->serving->supersedes() || $this->settings->hosts === []) {
                return;
            }
            $recipe = $this->builds->currentRecipe();
            $hosts = array_map(static fn ($host): int => $host->nodeId, $this->settings->hosts);
            $desired = DB::table('task_vm_image_recipe')->where('id', 1)->firstOrFail();
            if ($desired->identity !== $recipe->identity) {
                return;
            }
            $generation = (int) $desired->generation;
            $builds = TaskVmImageBuild::query()->where('recipe_identity', $recipe->identity)->where('recipe_generation', $generation)->orderBy('id')->get();
            $record = $builds->first();
            if ($record === null) {
                return;
            }
            $progress = $record->fleet_convergence ?? [];
            if (($progress['generation'] ?? null) !== $generation) {
                $progress = ['generation' => $generation, 'status' => 'ready', 'nodes' => []];
            }
            if (in_array($progress['status'], ['halted', 'succeeded'], true)) {
                return;
            }
            if ($progress['status'] === 'visiting') {
                $nodeId = is_int($progress['node_id'] ?? null) ? (string) $progress['node_id'] : 'unknown';
                $this->halt($record, $progress, 'Interrupted visit on Node '.$nodeId.'. Inspect Doctor before manual recovery.');

                return;
            }
            $ready = true;
            foreach ($hosts as $host) {
                $build = $builds->firstWhere('host_node_id', $host);
                if ($build?->state === TaskVmImageBuildState::Failed) {
                    $this->halt($record, $progress, "Image build [{$build->id}] failed on host [{$host}]. Inspect its build steps before recovery.");

                    return;
                }
                if ($build?->state !== TaskVmImageBuildState::Succeeded || $build->promoted_at === null || $build->recipe_generation !== $generation || ! isset($build->images['base'])) {
                    $ready = false;
                }
            }
            if (! $ready) {
                return;
            }
            $nodes = Node::query()->with('roles')->where('status', LifecycleStatus::Active)->where('platform', 'linux')->orderBy('id')->get()
                ->filter(fn (Node $node): bool => $this->eligibility->allows($node) && ($node->roles->isNotEmpty() || self::ciRunner($node)) && TaskVm::forNode($node) === null)
                ->sortBy(fn (Node $node): array => [$this->tier($node), $node->id]);
            foreach ($nodes as $node) {
                $visited = is_array($progress['nodes'] ?? null) ? $progress['nodes'] : [];
                $previous = is_array($visited[$node->id] ?? null) ? $visited[$node->id] : [];
                if (($previous['reason'] ?? null) === 'docker-provider:docker-ce') {
                    continue;
                }
                $verified = $node->settings['machine_recipe'] ?? null;
                if (is_array($verified) && ($verified['version'] ?? null) === $recipe->identity) {
                    continue;
                }
                if ($this->builds->currentRecipe()->identity !== $recipe->identity) {
                    return;
                }
                $hour = now('Europe/Amsterdam')->hour;
                if ($this->tier($node) >= 2 && ($hour < 3 || $hour >= 6)) {
                    $record->forceFill(['fleet_convergence' => [...$progress, 'status' => 'ready', 'node_id' => $node->id]])->save();

                    return;
                }
                $progress['status'] = 'visiting';
                $progress['node_id'] = $node->id;
                $record->forceFill(['fleet_convergence' => $progress])->save();
                try {
                    $result = $this->converge->execute($node, $recipe);
                    $completed = is_array($progress['nodes'] ?? null) ? $progress['nodes'] : [];
                    $completed[$node->id] = ['name' => $node->name, 'changes' => $result['changes'], 'finished_at' => now()->toIso8601String()];
                    $progress['nodes'] = $completed;
                    $progress['status'] = 'ready';
                    $record->forceFill(['fleet_convergence' => $progress])->save();
                } catch (Throwable $error) {
                    if ($error instanceof ResourceOperationException && $error->errorCode === 'node.recipe_docker_provider') {
                        $completed = is_array($progress['nodes'] ?? null) ? $progress['nodes'] : [];
                        $completed[$node->id] = ['name' => $node->name, 'status' => 'skipped', 'reason' => 'docker-provider:docker-ce', 'finished_at' => now()->toIso8601String()];
                        $record->forceFill(['fleet_convergence' => [...$progress, 'nodes' => $completed, 'status' => 'ready']])->save();

                        return;
                    }
                    $message = mb_substr(new CommandActivityInputSanitizer()->redactText($error->getMessage()), 0, 1500);
                    $this->halt($record, $progress, "Node [{$node->id}: {$node->name}] failed: {$message}");
                }

                return;
            }
            $record->forceFill(['fleet_convergence' => [...$progress, 'status' => 'succeeded', 'finished_at' => now()->toIso8601String()]])->save();
        });
    }

    private function tier(Node $node): int
    {
        if ($this->gateway->is($node)) {
            return 5;
        }
        // A roleless CI runner, such as Sabre, joins the service tier: overnight, after every app-dev-only Node.
        $tier = self::ciRunner($node) ? 2 : 1;
        foreach ($node->roles as $role) {
            $tier = max($tier, match ($role->role->value) {
                'gateway' => 5, 'vpn' => 4, 'app-prod' => 3, 'app-dev' => 1, default => 2,
            });
        }

        return $tier;
    }

    /** A roleless Node that an operator converged once with the ci-runner profile (ADR 0216). */
    private static function ciRunner(Node $node): bool
    {
        return $node->roles->isEmpty() && NodeRecipeProgram::recordedProfile($node) === NodeRecipeProgram::CiRunnerProfile;
    }

    /** @param array<string, mixed> $progress */
    private function halt(TaskVmImageBuild $record, array $progress, string $message): void
    {
        $summary = "Machine recipe [{$record->recipe_identity}] halted: {$message} No automatic rollback or retry.";
        $progress = [...$progress, 'status' => 'halted', 'message' => $summary, 'finished_at' => now()->toIso8601String()];
        $record->forceFill(['fleet_convergence' => $progress])->save();
        $record->forceFill(['fleet_convergence' => [...$progress, 'alert' => $this->alerts->halted($summary, $record->id)]])->save();
    }
}
