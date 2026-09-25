<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\ProjectLifecycleStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class ProjectLifecycleStepStore
{
    /** @return list<LifecycleStep> */
    public function ordered(OrbitApp $app, LifecyclePhase $phase): array
    {
        return ProjectLifecycleStep::query()
            ->where('app_id', $app->id)
            ->where('phase', $phase->value)
            ->orderBy('position')
            ->get()
            ->map(fn (ProjectLifecycleStep $row): LifecycleStep => $this->toDomain($row))
            ->all();
    }

    public function create(
        OrbitApp $app,
        LifecyclePhase $phase,
        LifecycleStep $step,
        ?string $before,
        ?string $after,
    ): LifecycleStep {
        return DB::transaction(function () use ($app, $phase, $step, $before, $after): LifecycleStep {
            OrbitApp::query()->lockForUpdate()->findOrFail($app->id);
            $placed = $this->insert($this->ordered($app, $phase), $step, $before, $after);
            $this->assertValid($placed);
            $this->persist($app, $phase, $placed);

            return $step;
        }, 5);
    }

    public function update(
        OrbitApp $app,
        LifecyclePhase $phase,
        string $name,
        ?string $command,
        ?int $timeoutSeconds,
        ?string $before,
        ?string $after,
        bool $hasCommand,
        bool $hasTimeout,
    ): LifecycleStep {
        return DB::transaction(function () use ($app, $phase, $name, $command, $timeoutSeconds, $before, $after, $hasCommand, $hasTimeout): LifecycleStep {
            OrbitApp::query()->lockForUpdate()->findOrFail($app->id);
            $existing = $this->ordered($app, $phase);
            $index = $this->indexByName($existing, $name, $phase);
            $current = $existing[$index];
            array_splice($existing, $index, 1);

            try {
                $updated = new LifecycleStep(
                    $current->name,
                    $hasCommand ? (string) $command : $current->command,
                    $hasTimeout ? (int) $timeoutSeconds : $current->timeoutSeconds,
                );
            } catch (InvalidArgumentException) {
                $this->invalid('A lifecycle step is invalid.');
            }

            $placed = $before === null && $after === null
                ? $this->restore($existing, $index, $updated)
                : $this->insert($existing, $updated, $before, $after);
            $this->assertValid($placed);
            $this->persist($app, $phase, $placed);

            return $updated;
        }, 5);
    }

    public function destroy(OrbitApp $app, LifecyclePhase $phase, string $name): LifecycleStep
    {
        return DB::transaction(function () use ($app, $phase, $name): LifecycleStep {
            OrbitApp::query()->lockForUpdate()->findOrFail($app->id);
            $existing = $this->ordered($app, $phase);
            $index = $this->indexByName($existing, $name, $phase);
            $removed = $existing[$index];
            array_splice($existing, $index, 1);
            $this->assertValid($existing);
            $this->persist($app, $phase, $existing);

            return $removed;
        }, 5);
    }

    /**
     * @param  list<LifecycleStep>  $steps
     * @return list<LifecycleStep>
     */
    private function insert(array $steps, LifecycleStep $step, ?string $before, ?string $after): array
    {
        foreach ($steps as $current) {
            if ($current->name === $step->name) {
                $this->invalid('The lifecycle step names must be unique.');
            }
        }

        if ($before !== null && $after !== null) {
            $this->invalid('Provide only one of before or after.');
        }

        if ($before !== null) {
            array_splice($steps, $this->indexOfPlacement($steps, $before), 0, [$step]);

            return $steps;
        }

        if ($after !== null) {
            array_splice($steps, $this->indexOfPlacement($steps, $after) + 1, 0, [$step]);

            return $steps;
        }

        $steps[] = $step;

        return $steps;
    }

    /**
     * @param  list<LifecycleStep>  $steps
     * @return list<LifecycleStep>
     */
    private function restore(array $steps, int $index, LifecycleStep $step): array
    {
        array_splice($steps, $index, 0, [$step]);

        return $steps;
    }

    /** @param list<LifecycleStep> $steps */
    private function indexOfPlacement(array $steps, string $name): int
    {
        foreach ($steps as $index => $step) {
            if ($step->name === $name) {
                return $index;
            }
        }

        $this->invalid('The placement step is unknown.');
    }

    /**
     * @param  list<LifecycleStep>  $steps
     */
    private function indexByName(array $steps, string $name, LifecyclePhase $phase): int
    {
        foreach ($steps as $index => $step) {
            if ($step->name === $name) {
                return $index;
            }
        }

        throw new ResourceOperationException(
            errorCode: $phase === LifecyclePhase::Setup ? 'setup_step.not_found' : 'teardown_step.not_found',
            message: 'The lifecycle step was not found.',
            status: 404,
        );
    }

    /** @param list<LifecycleStep> $steps */
    private function assertValid(array $steps): void
    {
        if (count($steps) > 32) {
            $this->invalid('The lifecycle list has too many steps.');
        }

        $totalTimeout = array_sum(array_map(
            static fn (LifecycleStep $step): int => $step->timeoutSeconds,
            $steps,
        ));

        if ($totalTimeout > LifecycleStep::MaxTotalTimeoutSeconds) {
            $this->invalid('The lifecycle list timeout total is too large.');
        }
    }

    /** @param list<LifecycleStep> $steps */
    private function persist(OrbitApp $app, LifecyclePhase $phase, array $steps): void
    {
        ProjectLifecycleStep::query()
            ->where('app_id', $app->id)
            ->where('phase', $phase->value)
            ->delete();

        foreach ($steps as $position => $step) {
            ProjectLifecycleStep::query()->create([
                'app_id' => $app->id,
                'phase' => $phase->value,
                'name' => $step->name,
                'command' => $step->command,
                'timeout_seconds' => $step->timeoutSeconds,
                'position' => $position,
            ]);
        }
    }

    private function toDomain(ProjectLifecycleStep $row): LifecycleStep
    {
        return new LifecycleStep($row->name, $row->command, $row->timeout_seconds);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['body' => [$message]]);
    }
}
