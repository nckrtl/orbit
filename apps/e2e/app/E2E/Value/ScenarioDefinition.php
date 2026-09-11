<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class ScenarioDefinition
{
    /**
     * @param  list<ScenarioAction>  $actions
     * @param  array<string, string>  $declaredInputs
     */
    public function __construct(
        public ScenarioId $id,
        public string $lane,
        public TopologyRecipe $recipe,
        public array $actions,
        public array $declaredInputs,
        public TopologyEndState $expectedEndState,
        public bool $observesPhp,
        public string $pestFilter,
        public bool $expectsConstructionFailure = false,
        public ?TopologyExtension $extension = null,
    ) {
        if (! in_array($lane, ['cold', 'snapshot'], true)) {
            throw new InvalidArgumentException('The scenario lane is invalid.');
        }
        if ($lane === 'cold' && $extension !== null) {
            throw new InvalidArgumentException('A cold scenario cannot declare a snapshot extension.');
        }
        if (
            $lane === 'snapshot'
            && $recipe->toArray() !== ($extension?->recipe() ?? TopologyRecipe::registered())->toArray()
        ) {
            throw new InvalidArgumentException('The snapshot scenario recipe does not match its extension declaration.');
        }
        if (
            $lane === 'snapshot'
            && ! array_all(
                ['setup', 'exercise', 'assertion'],
                fn (string $phase): bool => array_any(
                    $actions,
                    static fn (ScenarioAction $action): bool => $action->phase === $phase,
                ),
            )
        ) {
            throw new InvalidArgumentException('A snapshot scenario must declare setup, exercise, and assertion actions.');
        }
        if ($actions === []) {
            throw new InvalidArgumentException('The scenario action list is invalid.');
        }
        if ($declaredInputs === []) {
            throw new InvalidArgumentException('The scenario declared inputs are invalid.');
        }
        foreach ($declaredInputs as $path => $fingerprint) {
            if (
                preg_match('~\A(?!/)(?!.*(?:\A|/)\.\.(?:/|\z))[A-Za-z0-9._/-]+\z~D', $path) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1
            ) {
                throw new InvalidArgumentException('A scenario declared input is invalid.');
            }
        }
        if (preg_match('/\A[a-z0-9][a-z0-9 -]{2,127}\z/Di', $pestFilter) !== 1) {
            throw new InvalidArgumentException('The scenario Pest filter is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function normalized(): array
    {
        $inputs = $this->declaredInputs;
        ksort($inputs, SORT_STRING);

        $normalized = [
            'id' => $this->id->value,
            'lane' => $this->lane,
            'recipe' => $this->recipe->toArray(),
            'actions' => array_map(static fn (ScenarioAction $action): array => $action->toArray(), $this->actions),
            'declared_inputs' => $inputs,
            'expected_end_state' => $this->expectedEndState->toArray(),
            'observes_php' => $this->observesPhp,
            'pest_filter' => $this->pestFilter,
            'expects_construction_failure' => $this->expectsConstructionFailure,
        ];
        if ($this->lane === 'snapshot') {
            $normalized['extension'] = $this->extension?->value;
        }

        return $normalized;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->normalized(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
