<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

final readonly class ChoiceAnswer
{
    /**
     * @param  array<string, float>  $probabilities
     */
    public function __construct(
        public string $choice,
        public float $confidence,
        public array $probabilities = [],
    ) {}

    public function probabilityOf(string $option): float
    {
        return $this->probabilities[$option] ?? 0.0;
    }
}
