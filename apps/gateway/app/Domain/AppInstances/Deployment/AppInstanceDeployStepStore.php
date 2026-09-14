<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\AppInstanceDeployStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class AppInstanceDeployStepStore
{
    /** @return list<DeploymentStep> */
    public function ordered(AppInstance $instance): array
    {
        $rows = $instance->relationLoaded('deploySteps')
            ? $instance->deploySteps
            : $instance->deploySteps()->get();

        return $rows
            ->sortBy(static fn (AppInstanceDeployStep $row): int => ($row->phase === DeploymentPhase::BeforeActivation->value ? 0 : 1) * 1_000 + $row->position)
            ->values()
            ->map(fn (AppInstanceDeployStep $row): DeploymentStep => $this->toDomain($row))
            ->all();
    }

    /** @param list<DeploymentStep> $steps */
    public function replaceAll(AppInstance $instance, array $steps): void
    {
        $this->assertValid($instance, $steps);
        $this->persist($instance, $steps);
    }

    public function create(
        AppInstance $instance,
        DeploymentStep $step,
        ?string $before,
        ?string $after,
    ): DeploymentStep {
        $existing = $this->ordered($instance);

        foreach ($existing as $current) {
            if ($current->name === $step->name) {
                $this->invalid('The deployment step names must be unique.');
            }
        }

        $placed = $this->insert($existing, $step, $before, $after);
        $this->assertValid($instance, $placed);
        $this->persist($instance, $placed);

        return $step;
    }

    public function update(
        AppInstance $instance,
        string $name,
        ?string $command,
        ?DeploymentPhase $phase,
        ?int $timeoutSeconds,
        ?string $before,
        ?string $after,
        bool $hasCommand,
        bool $hasPhase,
        bool $hasTimeout,
    ): DeploymentStep {
        $existing = $this->ordered($instance);
        $index = $this->indexByName($existing, $name);
        $current = $existing[$index];
        array_splice($existing, $index, 1);

        try {
            $updated = new DeploymentStep(
                $current->name,
                $phase ?? $current->phase,
                $hasCommand ? (string) $command : $current->command,
                $hasTimeout ? (int) $timeoutSeconds : $current->timeoutSeconds,
            );
        } catch (InvalidArgumentException) {
            $this->invalid('A deployment step is invalid.');
        }

        if ($before === null && $after === null && ! $hasPhase) {
            array_splice($existing, $index, 0, [$updated]);
            $placed = $existing;
        } else {
            $placed = $this->insert($existing, $updated, $before, $after);
        }

        $this->assertValid($instance, $placed);
        $this->persist($instance, $placed);

        return $updated;
    }

    public function destroy(AppInstance $instance, string $name): DeploymentStep
    {
        $existing = $this->ordered($instance);
        $index = $this->indexByName($existing, $name);
        $removed = $existing[$index];
        array_splice($existing, $index, 1);
        $this->assertValid($instance, $existing);
        $this->persist($instance, $existing);

        return $removed;
    }

    /**
     * @param  list<DeploymentStep>  $steps
     * @return list<DeploymentStep>
     */
    private function insert(
        array $steps,
        DeploymentStep $step,
        ?string $before,
        ?string $after,
    ): array {
        if ($before !== null && $after !== null) {
            $this->invalid('Provide only one of before or after.');
        }

        if ($before !== null) {
            array_splice($steps, $this->indexOfPlacement($steps, $before, $step->phase), 0, [$step]);

            return $steps;
        }

        if ($after !== null) {
            array_splice($steps, $this->indexOfPlacement($steps, $after, $step->phase) + 1, 0, [$step]);

            return $steps;
        }

        $last = -1;

        foreach ($steps as $index => $existing) {
            if ($existing->phase === $step->phase) {
                $last = $index;
            }
        }

        if ($last === -1 && $step->phase === DeploymentPhase::BeforeActivation) {
            array_unshift($steps, $step);

            return $steps;
        }

        array_splice($steps, $last + 1, 0, [$step]);

        return $steps;
    }

    /**
     * @param  list<DeploymentStep>  $steps
     */
    private function indexOfPlacement(array $steps, string $name, DeploymentPhase $phase): int
    {
        foreach ($steps as $index => $step) {
            if ($step->name !== $name) {
                continue;
            }

            if ($step->phase !== $phase) {
                $this->invalid('The placement step must be in the same phase.');
            }

            return $index;
        }

        $this->invalid('The placement step is unknown.');
    }

    /**
     * @param  list<DeploymentStep>  $steps
     */
    private function indexByName(array $steps, string $name): int
    {
        foreach ($steps as $index => $step) {
            if ($step->name === $name) {
                return $index;
            }
        }

        throw new ResourceOperationException(
            errorCode: 'deploy_step.not_found',
            message: 'The deploy step was not found.',
            status: 404,
        );
    }

    /**
     * @param  list<DeploymentStep>  $steps
     */
    private function assertValid(AppInstance $instance, array $steps): void
    {
        $branch = is_string($instance->deployment_branch) ? $instance->deployment_branch : $instance->branch;

        if (! is_string($branch) || $branch === '') {
            $branch = 'main';
        }

        try {
            new DeploymentConfig($branch, $steps);
        } catch (InvalidArgumentException) {
            $this->invalid('The deployment configuration is invalid.');
        }
    }

    /** @param list<DeploymentStep> $steps */
    private function persist(AppInstance $instance, array $steps): void
    {
        DB::transaction(function () use ($instance, $steps): void {
            AppInstanceDeployStep::query()->where('app_instance_id', $instance->id)->delete();
            $positions = [
                DeploymentPhase::BeforeActivation->value => 0,
                DeploymentPhase::AfterActivation->value => 0,
            ];

            foreach ($steps as $step) {
                AppInstanceDeployStep::query()->create([
                    'app_instance_id' => $instance->id,
                    'name' => $step->name,
                    'phase' => $step->phase->value,
                    'command' => $step->command,
                    'timeout_seconds' => $step->timeoutSeconds,
                    'position' => $positions[$step->phase->value],
                ]);
                $positions[$step->phase->value]++;
            }
        });
    }

    private function toDomain(AppInstanceDeployStep $row): DeploymentStep
    {
        return new DeploymentStep(
            $row->name,
            DeploymentPhase::from($row->phase),
            $row->command,
            $row->timeout_seconds,
        );
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['body' => [$message]]);
    }
}
