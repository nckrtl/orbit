<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Data\Fleet\NodeRecipePlan;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\Node;
use App\Models\Tool;
use Closure;
use Throwable;

/** One Node only. The caller must stop advancement when this operation throws. */
final readonly class ConvergeNodeRecipeAction
{
    public function __construct(
        private ManagedNodeEligibility $eligibility,
        private NodeRoleConvergeLock $lock,
        private NodeUpdateLock $updateLock,
        private ToolManagerScopeLock $toolLocks,
        private NodeRecipeProgram $program,
        private ToolInspector $inspector,
        private VersionConstraint $constraints,
        private DebianVersionNormalizer $versions,
        private FleetNodeVerification $verifier,
    ) {}

    /** @return array{plan: NodeRecipePlan, applied: bool, changes: array<string, mixed>} */
    public function execute(Node $node, MachineRecipe $recipe, bool $dryRun = false, ?string $profile = null): array
    {
        $this->eligible($node);
        if ($dryRun) {
            return ['plan' => $this->plan($node, $recipe, $this->program->profile($node, $profile)), 'applied' => false, 'changes' => []];
        }

        return $this->lock->run($node, fn (): array => $this->withToolLocks($node, function () use ($node, $recipe, $profile): array {
            $node->refresh();
            $this->eligible($node);
            $profile = $this->program->profile($node, $profile);
            $plan = $this->plan($node, $recipe, $profile);

            return $this->updateLock->run($node, function () use ($node, $recipe, $plan, $profile): array {
                // A Node that may end partly changed must not appear converged, so the success marker goes first. The
                // profile is the Node's selection, not part of that marker, so a failed run keeps it.
                $settings = $node->settings ?? [];
                unset($settings['machine_recipe']);
                if ($profile !== null) {
                    $settings['machine_recipe'] = ['profile' => $profile];
                }
                $node->update(['settings' => $settings]);
                $changes = $this->program->apply($node, $recipe, $this->program->tools($node, $recipe, $profile), $profile);
                $this->verifyTools($node);
                $report = $this->verifier->verify($node, [], NodeAgentFootprint::Version);
                if (! $report['passed']) {
                    throw new ResourceOperationException('node.recipe_doctor_failed', 'Recipe convergence stopped at Doctor. The Node may be partly changed. No automatic rollback was attempted. Run Doctor for this Node before continuing.', 502);
                }
                $settings = $node->refresh()->settings ?? [];
                $settings['machine_recipe'] = ['version' => $recipe->identity, 'snapshot' => $recipe->snapshot, 'converged_at' => now()->toIso8601String(), ...(($changes['newer_than_recipe'] ?? []) === [] ? [] : ['newer_than_recipe' => $changes['newer_than_recipe']]), ...($profile === null ? [] : ['profile' => $profile])];
                $node->update(['settings' => $settings]);

                return ['plan' => $plan, 'applied' => true, 'changes' => $changes];
            });
        }), errorCode: 'node.recipe_failed');
    }

    /**
     * @param  Closure(): array{plan: NodeRecipePlan, applied: bool, changes: array<string, mixed>}  $operation
     * @return array{plan: NodeRecipePlan, applied: bool, changes: array<string, mixed>}
     */
    private function withToolLocks(Node $node, Closure $operation, int $index = 0): array
    {
        $managers = [ToolManagerName::Apt, ToolManagerName::Composer, ToolManagerName::Vp, ToolManagerName::Brew];
        if (! isset($managers[$index])) {
            return $operation();
        }

        return $this->toolLocks->run($node->id, $managers[$index], fn (): array => $this->withToolLocks($node, $operation, $index + 1));
    }

    private function eligible(Node $node): void
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.recipe_unsupported', 'Recipe convergence requires an active managed Linux Node.', 422);
        }
    }

    private function plan(Node $node, MachineRecipe $recipe, ?string $profile): NodeRecipePlan
    {
        $pins = $this->program->tools($node, $recipe, $profile);
        $this->checkConstraints($node, $pins);
        // Another PHP source or a PHP package outside the recipe refuses the plan, also in a dry run.
        $this->program->phpPreflight($node, $recipe, $pins, $profile);
        $inspection = $this->program->inspect($node, $recipe, $pins);

        return new NodeRecipePlan($node->id, $recipe->identity, $recipe->snapshot, array_values($node->roles->map(fn ($role): string => $role->role->value)->all()), $inspection['tools'], $inspection['actions']);
    }

    /** @param array<string, ToolPin> $pins */
    private function checkConstraints(Node $node, array $pins): void
    {
        foreach ($this->tools($node) as $tool) {
            if ($tool->manager->name !== 'apt' || ! isset($pins[$tool->package]) || $tool->version_constraint === null) {
                continue;
            }
            $version = $this->versions->normalize($pins[$tool->package]->version);
            if ($version === null || ! $this->constraints->isValid($tool->version_constraint) || ! $this->constraints->allows($version, $tool->version_constraint)) {
                throw new ResourceOperationException('node.recipe_tool_conflict', "Recipe pin for [{$tool->package}] conflicts with managed Tool [{$tool->id}]. Nothing was changed.", 409);
            }
        }
    }

    private function verifyTools(Node $node): void
    {
        foreach ($this->tools($node) as $tool) {
            try {
                $observed = $this->inspector->inspect($tool);
            } catch (Throwable $exception) {
                throw new ResourceOperationException('node.recipe_inventory_failed', "Recipe convergence could not verify managed Tool [{$tool->id}]. No automatic rollback was attempted.", 502, $exception);
            }
            if (! $observed->installed || ($tool->version_constraint !== null && ($observed->normalizedVersion === null || ! $this->constraints->isValid($tool->version_constraint) || ! $this->constraints->allows($observed->normalizedVersion, $tool->version_constraint)))) {
                throw new ResourceOperationException('node.recipe_inventory_failed', "Recipe convergence stopped at managed Tool [{$tool->id}] verification. No automatic rollback was attempted.", 502);
            }
        }
    }

    /** @return list<Tool> */
    private function tools(Node $node): array
    {
        return array_values(Tool::query()->with(['node', 'manager'])->where('node_id', $node->id)->orderBy('id')->get()->all());
    }
}
